"""
Tests for strongswan_api.py. Run: python3 -m unittest -v infra/strongswan/api/test_strongswan_api.py

A fake `ipsec` script records every call and prints a canned `ipsec status`,
so no StrongSwan is needed.
"""

import http.client
import json
import os
import socket
import stat
import sys
import tempfile
import threading
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import strongswan_api as api  # noqa: E402

SECRETS = """\
%any %any : PSK "shared-psk-value"
x.xab.su : RSA "privkey.pem"
alice : EAP "111111"
bob : EAP "222222"
al : EAP "333333"
bob : EAP "222222"
"""

STATUS = """\
Security Associations (2 up, 0 connecting):
   ikev2-vpn[30]: ESTABLISHED 22 hours ago, 5.129.226.167[x.xab.su]...185.195.152.36[bob]
   ikev2-vpn{29}:  INSTALLED, TUNNEL, reqid 4, ESP in UDP SPIs: c8091412_i c08b4f3d_o
   ikev2-vpn[31]: ESTABLISHED 5 minutes ago, 5.129.226.167[x.xab.su]...10.0.0.9[alice]
"""

API_KEY = "test-key-0123456789"


class FakeIpsecMixin:
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.secrets = os.path.join(self.dir, "ipsec.secrets")
        with open(self.secrets, "w") as f:
            f.write(SECRETS)
        os.chmod(self.secrets, 0o600)

        self.calls = os.path.join(self.dir, "calls.log")
        self.status_file = os.path.join(self.dir, "status.txt")
        with open(self.status_file, "w") as f:
            f.write(STATUS)

        self.ipsec = os.path.join(self.dir, "ipsec")
        with open(self.ipsec, "w") as f:
            f.write(
                "#!/bin/sh\n"
                f'echo "$@" >> "{self.calls}"\n'
                f'if [ "$1" = "status" ]; then cat "{self.status_file}"; fi\n'
            )
        os.chmod(self.ipsec, 0o755)

        self.store = api.SecretsStore(self.secrets, self.ipsec, os.path.join(self.dir, "lock"))

    def read_secrets(self):
        with open(self.secrets) as f:
            return f.read()

    def ipsec_calls(self):
        if not os.path.exists(self.calls):
            return []
        with open(self.calls) as f:
            return f.read().splitlines()


class SecretsStoreTest(FakeIpsecMixin, unittest.TestCase):
    def test_users_are_unique_and_ordered(self):
        self.assertEqual(self.store.users(), ["alice", "bob", "al"])

    def test_add_new_user_appends_line_and_rereads_secrets(self):
        self.assertEqual(self.store.add("carol", "444444"), "added")
        self.assertIn('carol : EAP "444444"', self.read_secrets())
        self.assertIn("rereadsecrets", self.ipsec_calls())
        self.assertNotIn("restart", " ".join(self.ipsec_calls()))

    def test_add_existing_user_updates_password_and_drops_duplicates(self):
        self.assertEqual(self.store.add("bob", "999999"), "updated")
        content = self.read_secrets()
        self.assertEqual(content.count("bob : EAP"), 1)
        self.assertIn('bob : EAP "999999"', content)

    def test_add_same_password_is_noop(self):
        self.assertEqual(self.store.add("alice", "111111"), "unchanged")
        self.assertEqual(self.ipsec_calls(), [])

    def test_remove_matches_exact_name_only(self):
        # The old API removed every line containing "al": alice and the PSK line ("shared")...
        self.assertTrue(self.store.remove("al"))
        content = self.read_secrets()
        self.assertNotIn('al : EAP "333333"', content)
        self.assertIn('alice : EAP "111111"', content)
        self.assertIn("%any %any : PSK", content)
        self.assertIn("x.xab.su : RSA", content)

    def test_remove_terminates_only_that_users_session(self):
        self.store.remove("bob")
        calls = self.ipsec_calls()
        self.assertIn("down ikev2-vpn[30]", calls)
        self.assertNotIn("down ikev2-vpn[31]", calls)
        self.assertNotIn("bob : EAP", self.read_secrets())

    def test_remove_absent_user_still_drops_live_session(self):
        self.assertFalse(self.store.remove("ghost"))
        with open(self.status_file, "a") as f:
            f.write("   ikev2-vpn[40]: ESTABLISHED 1 second ago, 1.1.1.1[x]...2.2.2.2[ghost]\n")
        self.store.remove("ghost")
        self.assertIn("down ikev2-vpn[40]", self.ipsec_calls())

    def test_write_keeps_mode_and_makes_backup(self):
        self.store.add("carol", "444444")
        self.assertEqual(stat.S_IMODE(os.stat(self.secrets).st_mode), 0o600)
        with open(self.secrets + ".bak") as f:
            self.assertEqual(f.read(), SECRETS)

    def test_rejects_injection_attempts(self):
        for username, password in [
            ('evil\n%any : PSK "x"', "123456"),
            ("user", 'abc"def'),
            ("two words", "123456"),
            ("", "123456"),
            ("user", "12"),
        ]:
            with self.assertRaises(api.ApiError):
                self.store.add(username, password)
        self.assertEqual(self.read_secrets(), SECRETS)

    def test_sessions_parse_identities(self):
        self.assertEqual(
            self.store.sessions(),
            [{"sa": "ikev2-vpn[30]", "identity": "bob"}, {"sa": "ikev2-vpn[31]", "identity": "alice"}],
        )


class UnixHTTPConnection(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__("localhost")
        self.unix_path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.connect(self.unix_path)


class HttpApiTest(FakeIpsecMixin, unittest.TestCase):
    def setUp(self):
        super().setUp()
        self.sock_path = os.path.join(self.dir, "run", "api.sock")
        handler = api.make_handler(self.store, API_KEY)
        handler.log_message = lambda *a: None
        self.server = api.build_server("unix:" + self.sock_path, handler, 0o660, -1)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()

    def request(self, method, path, body=None, headers=None):
        conn = UnixHTTPConnection(self.sock_path)
        payload = json.dumps(body).encode() if body is not None else None
        hdrs = {"Content-Type": "application/json", **(headers or {})}
        conn.request(method, path, body=payload, headers=hdrs)
        resp = conn.getresponse()
        data = json.loads(resp.read() or b"{}")
        conn.close()
        return resp.status, data

    def test_socket_permissions(self):
        self.assertEqual(stat.S_IMODE(os.stat(self.sock_path).st_mode), 0o660)

    def test_health_needs_no_key(self):
        status, data = self.request("GET", "/health")
        self.assertEqual(status, 200)
        self.assertEqual(data["status"], "ok")

    def test_rejects_wrong_key(self):
        status, data = self.request("POST", "/get_users", {"key": "wrong"})
        self.assertEqual(status, 403)
        self.assertEqual(data["status"], "error")

    def test_key_in_header(self):
        status, data = self.request("POST", "/get_users", {}, {"X-Api-Key": API_KEY})
        self.assertEqual(status, 200)
        self.assertEqual(data["users"], ["alice", "bob", "al"])

    def test_key_in_body_for_old_clients(self):
        status, data = self.request("POST", "/get_users", {"key": API_KEY})
        self.assertEqual(status, 200)

    def test_add_and_remove_roundtrip(self):
        status, data = self.request("POST", "/add_user", {"key": API_KEY, "username": "carol", "password": "444444"})
        self.assertEqual((status, data["result"]), (200, "added"))
        status, data = self.request("POST", "/remove_user", {"key": API_KEY, "username": "carol"})
        self.assertEqual((status, data["removed"]), (200, True))
        self.assertNotIn("carol", self.store.users())

    def test_invalid_username_is_400(self):
        status, data = self.request("POST", "/add_user", {"key": API_KEY, "username": "a b", "password": "444444"})
        self.assertEqual(status, 400)

    def test_unknown_route_is_404(self):
        status, _ = self.request("POST", "/restart", {"key": API_KEY})
        self.assertEqual(status, 404)

    def test_bad_json_is_400(self):
        conn = UnixHTTPConnection(self.sock_path)
        conn.request("POST", "/get_users", body=b"{nope", headers={"X-Api-Key": API_KEY})
        self.assertEqual(conn.getresponse().status, 400)
        conn.close()


if __name__ == "__main__":
    unittest.main()
