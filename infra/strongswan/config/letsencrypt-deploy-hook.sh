#!/bin/bash
# /etc/letsencrypt/renewal-hooks/deploy/strongswan.sh
# После продления сертификата копируем ключ туда, где его ищет charon
# (строка "<домен> : RSA privkey.pem" в /etc/ipsec.secrets), и перечитываем.
set -euo pipefail

for domain in $RENEWED_DOMAINS; do
    src="/etc/letsencrypt/live/${domain}/privkey.pem"
    [ -f "$src" ] || continue
    install -m 600 -o root -g root "$src" /etc/ipsec.d/private/privkey.pem
done

ipsec rereadall
ipsec reload
