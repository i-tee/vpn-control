#!/usr/bin/env python3
"""
StrongSwan user API for Gatekeeper.

Manages EAP users in /etc/ipsec.secrets for an IKEv2 server running the
legacy stroke interface (ipsec.conf / `ipsec` command). Standard library only.

Differences from the original Flask API it replaces:
  * listens on a unix socket by default, so it is not reachable from the network;
  * exact username matching (the old API deleted every line *containing* the name,
    which could wipe the server's RSA and PSK lines);
  * `ipsec rereadsecrets` instead of `ipsec restart`, so other users stay connected;
  * a removed user's live sessions are terminated, nobody else's;
  * strict validation of usernames and passwords (no quotes or newlines can reach
    the secrets file), atomic writes with a backup of the previous version;
  * constant-time API key comparison, key accepted in X-Api-Key header or JSON body.

Endpoints (JSON in, JSON out):
  GET  /health                       -> {"status": "ok", "charon": bool}
  POST /get_users                    -> {"status": "success", "users": [...]}
  POST /add_user    {username, password}
  POST /remove_user {username}
  POST /sessions                     -> {"status": "success", "sessions": [{"sa", "identity"}]}

Configuration via environment (see strongswan-api.env.example):
  API_KEY       required
  LISTEN        unix:/run/strongswan-api/api.sock (default) or tcp:127.0.0.1:5000
  SOCKET_MODE   octal permissions of the unix socket, default 0660
  SOCKET_GID    group owner of the unix socket, default 33 (www-data), -1 to keep
  SECRETS_FILE  default /etc/ipsec.secrets
  IPSEC_BIN     default ipsec
  LOCK_FILE     default /run/lock/strongswan-api.lock
"""

import fcntl
import hmac
import json
import logging
import os
import re
import shutil
import socketserver
import subprocess
import sys
import tempfile
from http.server import BaseHTTPRequestHandler, HTTPServer

# Must stay in sync with VpnService::USERNAME_PATTERN / PASSWORD_PATTERN in Laravel.
USERNAME_RE = re.compile(r"^[A-Za-z0-9_.-]{1,64}$")
PASSWORD_RE = re.compile(r"^[A-Za-z0-9_.@!#%+=-]{4,128}$")

EAP_LINE_RE = re.compile(r'^\s*(?P<user>[^\s:"]+)\s*:\s*EAP\s+"(?P<password>[^"]*)"\s*$')
# ikev2-vpn[30]: ESTABLISHED 22 hours ago, 5.129.226.167[x.xab.su]...185.195.152.36[KZazulya]
SA_LINE_RE = re.compile(r"^\s*(?P<sa>[\w.-]+\[\d+\]): ESTABLISHED\b.*\.\.\.\S+?\[(?P<identity>[^\]]*)\]\s*$")

MAX_BODY = 64 * 1024

log = logging.getLogger("strongswan-api")


class ApiError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status
        self.message = message


def validate_username(username):
    if not isinstance(username, str) or not USERNAME_RE.match(username):
        raise ApiError(400, "Invalid username")


def validate_password(password):
    if not isinstance(password, str) or not PASSWORD_RE.match(password):
        raise ApiError(400, "Invalid password")


class SecretsStore:
    """Reads and rewrites EAP lines of ipsec.secrets, leaving every other line intact."""

    def __init__(self, path, ipsec_bin="ipsec", lock_path="/run/lock/strongswan-api.lock"):
        self.path = path
        self.ipsec_bin = ipsec_bin
        self.lock_path = lock_path

    # --- reading -------------------------------------------------------------

    def _read_lines(self):
        with open(self.path, "r", encoding="utf-8") as f:
            return f.read().splitlines()

    def users(self):
        """Ordered unique list of EAP usernames."""
        seen = []
        for line in self._read_lines():
            m = EAP_LINE_RE.match(line)
            if m and m.group("user") not in seen:
                seen.append(m.group("user"))
        return seen

    # --- writing -------------------------------------------------------------

    def add(self, username, password):
        """Add a user or update their password. Returns 'added', 'updated' or 'unchanged'."""
        validate_username(username)
        validate_password(password)
        new_line = f'{username} : EAP "{password}"'

        with self._locked():
            lines = self._read_lines()
            result = "added"
            out = []
            for line in lines:
                m = EAP_LINE_RE.match(line)
                if m and m.group("user") == username:
                    if result == "added":
                        result = "unchanged" if m.group("password") == password else "updated"
                        out.append(new_line)
                    # later duplicates of the same user (left by the old API) are dropped
                    continue
                out.append(line)
            if result == "added":
                out.append(new_line)

            if out != lines:
                self._write(out)
                self.reload()
            return result

    def remove(self, username):
        """Remove a user and terminate their live sessions. Returns True if the user existed."""
        validate_username(username)

        with self._locked():
            lines = self._read_lines()
            out = [
                line for line in lines
                if not ((m := EAP_LINE_RE.match(line)) and m.group("user") == username)
            ]
            existed = len(out) != len(lines)
            if existed:
                self._write(out)
                self.reload()

        # Even if the line was already gone, a session may still be up: drop it.
        self.terminate(username)
        return existed

    def _write(self, lines):
        """Atomic replace that keeps mode and ownership, previous version saved as .bak."""
        st = os.stat(self.path)
        directory = os.path.dirname(os.path.abspath(self.path))
        shutil.copy2(self.path, self.path + ".bak")

        fd, tmp = tempfile.mkstemp(prefix=".ipsec.secrets.", dir=directory)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                f.write("\n".join(lines) + "\n")
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp, st.st_mode & 0o7777)
            try:
                os.chown(tmp, st.st_uid, st.st_gid)
            except PermissionError:
                pass  # tests run unprivileged; in production we are root
            os.replace(tmp, self.path)
        except BaseException:
            if os.path.exists(tmp):
                os.unlink(tmp)
            raise

    def _locked(self):
        return _FileLock(self.lock_path)

    # --- charon --------------------------------------------------------------

    def _ipsec(self, *args, check=True):
        return subprocess.run(
            [self.ipsec_bin, *args],
            check=check,
            capture_output=True,
            text=True,
            timeout=20,
        )

    def reload(self):
        self._ipsec("rereadsecrets")

    def sessions(self):
        result = self._ipsec("status", check=False)
        sessions = []
        for line in result.stdout.splitlines():
            m = SA_LINE_RE.match(line)
            if m:
                sessions.append({"sa": m.group("sa"), "identity": m.group("identity")})
        return sessions

    def terminate(self, username):
        terminated = []
        for s in self.sessions():
            if s["identity"] == username:
                self._ipsec("down", s["sa"], check=False)
                terminated.append(s["sa"])
        if terminated:
            log.info("terminated sessions of %s: %s", username, ", ".join(terminated))
        return terminated

    def charon_running(self):
        try:
            return self._ipsec("status", check=False).returncode == 0
        except (OSError, subprocess.SubprocessError):
            return False


class _FileLock:
    def __init__(self, path):
        self.path = path
        self.fd = None

    def __enter__(self):
        os.makedirs(os.path.dirname(self.path), exist_ok=True)
        self.fd = os.open(self.path, os.O_CREAT | os.O_RDWR, 0o600)
        fcntl.flock(self.fd, fcntl.LOCK_EX)
        return self

    def __exit__(self, *exc):
        fcntl.flock(self.fd, fcntl.LOCK_UN)
        os.close(self.fd)


def make_handler(store, api_key):
    class Handler(BaseHTTPRequestHandler):
        server_version = "strongswan-api/2"

        # unix sockets have no client address
        def address_string(self):
            return self.client_address[0] if self.client_address else "unix"

        def log_message(self, fmt, *args):
            log.info("%s %s", self.address_string(), fmt % args)

        def _send(self, status, payload):
            body = json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _read_json(self):
            length = int(self.headers.get("Content-Length") or 0)
            if length > MAX_BODY:
                raise ApiError(413, "Request too large")
            raw = self.rfile.read(length) if length else b""
            if not raw:
                return {}
            try:
                data = json.loads(raw)
            except ValueError:
                raise ApiError(400, "Invalid JSON")
            if not isinstance(data, dict):
                raise ApiError(400, "JSON object expected")
            return data

        def _authorize(self, data):
            supplied = self.headers.get("X-Api-Key") or data.get("key") or ""
            if not isinstance(supplied, str) or not hmac.compare_digest(supplied.encode(), api_key.encode()):
                raise ApiError(403, "Invalid or missing key")

        def do_GET(self):
            if self.path == "/health":
                self._send(200, {"status": "ok", "charon": store.charon_running()})
            else:
                self._send(404, {"status": "error", "message": "Not found"})

        def do_POST(self):
            try:
                data = self._read_json()
                self._authorize(data)
                route = self.path.rstrip("/")

                if route == "/get_users":
                    self._send(200, {"status": "success", "users": store.users()})

                elif route == "/add_user":
                    username, password = data.get("username"), data.get("password")
                    result = store.add(username, password)
                    log.info("add_user %s: %s", username, result)
                    self._send(200, {"status": "success", "message": f"User {username} {result}", "result": result})

                elif route == "/remove_user":
                    username = data.get("username")
                    existed = store.remove(username)
                    log.info("remove_user %s: %s", username, "removed" if existed else "absent")
                    self._send(200, {"status": "success", "message": f"User {username} removed", "removed": existed})

                elif route == "/sessions":
                    self._send(200, {"status": "success", "sessions": store.sessions()})

                else:
                    raise ApiError(404, "Not found")

            except ApiError as e:
                self._send(e.status, {"status": "error", "message": e.message})
            except subprocess.CalledProcessError as e:
                log.error("ipsec %s failed: %s", " ".join(e.cmd[1:]), (e.stderr or "").strip())
                self._send(500, {"status": "error", "message": "ipsec command failed"})
            except Exception:  # noqa: BLE001 - last resort, keep the server alive
                log.exception("unexpected error")
                self._send(500, {"status": "error", "message": "Internal error"})

    return Handler


class UnixHTTPServer(socketserver.UnixStreamServer):
    def __init__(self, path, handler, mode, gid):
        self.socket_path = path
        self.socket_mode = mode
        self.socket_gid = gid
        super().__init__(path, handler)

    def server_bind(self):
        try:
            os.unlink(self.socket_path)
        except FileNotFoundError:
            pass
        super().server_bind()
        os.chmod(self.socket_path, self.socket_mode)
        if self.socket_gid >= 0:
            os.chown(self.socket_path, -1, self.socket_gid)


def build_server(listen, handler, socket_mode=0o660, socket_gid=33):
    if listen.startswith("unix:"):
        path = listen[len("unix:"):]
        os.makedirs(os.path.dirname(path), exist_ok=True)
        return UnixHTTPServer(path, handler, socket_mode, socket_gid)
    if listen.startswith("tcp:"):
        host, _, port = listen[len("tcp:"):].rpartition(":")
        return HTTPServer((host, int(port)), handler)
    raise SystemExit(f"Unsupported LISTEN value: {listen}")


def main():
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s", stream=sys.stderr)

    api_key = os.environ.get("API_KEY", "")
    if len(api_key) < 16:
        raise SystemExit("API_KEY is missing or shorter than 16 characters")

    store = SecretsStore(
        os.environ.get("SECRETS_FILE", "/etc/ipsec.secrets"),
        os.environ.get("IPSEC_BIN", "ipsec"),
        os.environ.get("LOCK_FILE", "/run/lock/strongswan-api.lock"),
    )
    listen = os.environ.get("LISTEN", "unix:/run/strongswan-api/api.sock")
    server = build_server(
        listen,
        make_handler(store, api_key),
        int(os.environ.get("SOCKET_MODE", "0660"), 8),
        int(os.environ.get("SOCKET_GID", "33")),
    )

    log.info("listening on %s, managing %s", listen, store.path)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
