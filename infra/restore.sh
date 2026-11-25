#!/bin/bash
# Полное восстановление Gatekeeper на новом сервере (Ubuntu 22.04/24.04) из репозитория
# и зашифрованного бэкапа. Запускать от root из копии репозитория:
#
#   git clone https://github.com/i-tee/vpn-control.git /opt/gatekeeper-src
#   scp gatekeeper-backup-*.tar.gz.gpg ~/.gatekeeper-backup-passphrase root@<новый>:/root/
#   cd /opt/gatekeeper-src
#   sudo BACKUP=/root/gatekeeper-backup-20261125.tar.gz.gpg \
#        BACKUP_PASSPHRASE_FILE=/root/.gatekeeper-backup-passphrase \
#        DEPLOY_PUBKEY="$(cat gatekeeper_deploy.pub)" \
#        ./infra/restore.sh
#
# ДО запуска: записи A для VPN_DOMAIN и APP_DOMAIN из .env бэкапа должны смотреть
# на этот сервер — иначе Caddy не получит сертификат, и проверка деплоя не пройдёт.
#
# Порядок повторяет переезд 17.11.2026 (см. infra/strongswan/README.md, infra/app/README.md):
#   1. сертификат Let's Encrypt из бэкапа → 2. StrongSwan и API (install.sh) →
#   3. юзеры VPN и ключ API из бэкапа → 4. Docker и пользователь deploy (setup-server.sh) →
#   5. .env и база → 6. сборка образа здесь же и deploy.sh → 7. вебхук бота →
#   8. продление сертификата через webroot → 9. сверка.
#
# Не прогонялся целиком: собран из шагов, которые выполнялись руками при переезде.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP="${BACKUP:?Укажи BACKUP=путь к gatekeeper-backup-*.tar.gz(.gpg)}"
DEPLOY_PUBKEY="${DEPLOY_PUBKEY:-}"
BUILD_LOCALLY="${BUILD_LOCALLY:-1}"

[ "$(id -u)" -eq 0 ] || { echo "Запускать от root"; exit 1; }
step() { echo; echo "######## $*"; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
chmod 700 "$WORK"

step "1/9 Распаковка бэкапа"
if [[ "$BACKUP" == *.gpg ]]; then
    : "${BACKUP_PASSPHRASE_FILE:?Для .gpg укажи BACKUP_PASSPHRASE_FILE}"
    command -v gpg >/dev/null || apt-get install -y -q gnupg
    gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$BACKUP_PASSPHRASE_FILE" \
        --decrypt "$BACKUP" > "$WORK/backup.tar.gz"
else
    cp "$BACKUP" "$WORK/backup.tar.gz"
fi
tar xzf "$WORK/backup.tar.gz" -C "$WORK"

ENV_FILE="$WORK/opt/gatekeeper/.env"
DB_FILE="$(find "$WORK/opt/gatekeeper/storage" -name '*.sqlite' | head -1)"
for f in "$ENV_FILE" "$DB_FILE" "$WORK/etc/ipsec.secrets" "$WORK/etc/strongswan-api.env"; do
    [ -f "$f" ] || { echo "В бэкапе нет $f"; exit 1; }
done
envv() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
VPN_DOMAIN="$(envv VPN_DOMAIN)"
APP_DOMAIN="$(envv APP_DOMAIN)"
LE_EMAIL="${LE_EMAIL:-$(envv ACME_EMAIL)}"
echo "VPN: $VPN_DOMAIN, админка и бот: $APP_DOMAIN, юзеров VPN в бэкапе: $(grep -cE ':\s*EAP\s' "$WORK/etc/ipsec.secrets")"

MY_IP="$(curl -s -m 10 https://api.ipify.org || true)"
for d in "$VPN_DOMAIN" "$APP_DOMAIN"; do
    resolved="$(getent hosts "$d" | awk '{print $1}' | head -1)"
    [ "$resolved" = "$MY_IP" ] || echo "    ВНИМАНИЕ: $d смотрит на ${resolved:-ничего}, а этот сервер $MY_IP. Поправь DNS, иначе сертификаты не выпишутся."
done

step "2/9 Сертификат Let's Encrypt из бэкапа"
if [ -d "$WORK/etc/letsencrypt/live/$VPN_DOMAIN" ] && [ ! -d "/etc/letsencrypt/live/$VPN_DOMAIN" ]; then
    mkdir -p /etc/letsencrypt
    cp -a "$WORK/etc/letsencrypt/." /etc/letsencrypt/
    echo "    восстановлен: $(openssl x509 -in "/etc/letsencrypt/live/$VPN_DOMAIN/cert.pem" -noout -enddate)"
else
    echo "    пропускаю (уже есть или нет в бэкапе) — install.sh выпустит новый"
fi

step "3/9 StrongSwan и VPN API"
VPN_DOMAIN="$VPN_DOMAIN" LE_EMAIL="$LE_EMAIL" ACME_MODE=standalone bash "$HERE/infra/strongswan/install.sh"

step "4/9 Юзеры VPN и ключ API из бэкапа"
install -m 600 -o root -g root "$WORK/etc/ipsec.secrets" /etc/ipsec.secrets
install -m 600 -o root -g root "$WORK/etc/strongswan-api.env" /etc/strongswan-api.env
ipsec rereadall
systemctl restart strongswan-api
sleep 2
curl -s -m 5 --unix-socket /run/strongswan-api/api.sock http://localhost/health; echo

step "5/9 Docker и пользователь deploy"
DEPLOY_PUBKEY="$DEPLOY_PUBKEY" bash "$HERE/infra/app/setup-server.sh"

step "6/9 .env и база"
install -m 600 -o deploy -g deploy "$ENV_FILE" /opt/gatekeeper/.env
rm -f /opt/gatekeeper/storage/database/database.sqlite-wal /opt/gatekeeper/storage/database/database.sqlite-shm
install -m 644 -o 33 -g 33 "$DB_FILE" /opt/gatekeeper/storage/database/database.sqlite
echo "    база: $(sqlite3 /opt/gatekeeper/storage/database/database.sqlite 'select count(*) from users') юзеров, целостность $(sqlite3 /opt/gatekeeper/storage/database/database.sqlite 'pragma integrity_check')"

if [ "$BUILD_LOCALLY" = "1" ]; then
    step "7/9 Сборка образа и деплой"
    TAG="$(git -C "$HERE" rev-parse HEAD)"
    docker build -f "$HERE/infra/app/Dockerfile" -t "gatekeeper:$TAG" "$HERE"
    docker save "gatekeeper:$TAG" | gzip -1 | sudo -u deploy /opt/gatekeeper/bin/deploy.sh deploy "$TAG"

    step "8/9 Вебхук бота"
    (cd /opt/gatekeeper && docker compose exec -T app php artisan telegraph:set-webhook 1 \
        --secret="$(grep -E '^TELEGRAPH_WEBHOOK_SECRET=' .env | cut -d= -f2- | tr -d '"')" </dev/null) | tail -1

    step "9/9 Продление сертификата VPN через Caddy и сверка"
    VPN_DOMAIN="$VPN_DOMAIN" ACME_MODE=webroot bash "$HERE/infra/strongswan/install.sh" | tail -3
    (cd /opt/gatekeeper && docker compose exec -T app php artisan vpn:reconcile </dev/null) | tail -2
else
    echo
    echo "BUILD_LOCALLY=0: образ соберёт GitHub Actions. Обнови секреты DEPLOY_HOST и"
    echo "DEPLOY_KNOWN_HOSTS и запусти workflow, потом шаги 8–9 руками (см. infra/RESTORE.md)."
fi

echo
echo "Готово. Осталось:"
echo "  - GitHub: секреты DEPLOY_HOST=$MY_IP и DEPLOY_KNOWN_HOSTS (ssh-keyscan -t ed25519 $MY_IP), переменная DEPLOY_ENABLED=true"
echo "  - войти в https://$APP_DOMAIN/admin старым логином админа"
