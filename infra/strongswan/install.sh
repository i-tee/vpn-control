#!/bin/bash
# Установка или обновление StrongSwan IKEv2 + Gatekeeper API на Ubuntu 22.04/24.04.
#
#   sudo VPN_DOMAIN=x.example.com LE_EMAIL=admin@example.com ./install.sh
#
# Идемпотентно. На живом сервере:
#   * не трогает EAP-юзеров в /etc/ipsec.secrets;
#   * не перезапускает charon, а делает reload — подключённые клиенты остаются;
#   * не перевыпускает сертификат, если он уже есть;
#   * перед заменой конфигов кладёт рядом копию *.bak-<дата>.
#
# Переменные:
#   VPN_DOMAIN   домен сервера, он же удалённый идентификатор в профилях (обязательно)
#   LE_EMAIL     почта для Let's Encrypt (обязательно, если сертификата ещё нет)
#   ACME_MODE    standalone (certbot сам слушает :80) или webroot (порт 80 занят Caddy)
#   ACME_WEBROOT каталог для webroot-режима, по умолчанию /var/www/acme
#   VPN_SUBNET   подсеть клиентов, по умолчанию 10.250.0.0/16
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VPN_DOMAIN="${VPN_DOMAIN:?Укажи VPN_DOMAIN}"
LE_EMAIL="${LE_EMAIL:-}"
ACME_MODE="${ACME_MODE:-standalone}"
ACME_WEBROOT="${ACME_WEBROOT:-/var/www/acme}"
VPN_SUBNET="${VPN_SUBNET:-10.250.0.0/16}"
STAMP="$(date +%Y%m%d-%H%M%S)"

[ "$(id -u)" -eq 0 ] || { echo "Запускать от root"; exit 1; }

step() { echo; echo "==> $*"; }

backup_if_differs() {
    # backup_if_differs <новый файл> <целевой файл>
    if [ -f "$2" ] && ! cmp -s "$1" "$2"; then
        cp -a "$2" "$2.bak-$STAMP"
        echo "    старая версия сохранена: $2.bak-$STAMP"
    fi
}

step "Пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
# --no-upgrade: обновление пакетов strongswan перезапустило бы charon и оборвало все сессии
apt-get install -y -q --no-upgrade strongswan strongswan-pki libcharon-extra-plugins \
    libcharon-extauth-plugins libstrongswan-standard-plugins \
    certbot python3 iptables curl

step "Форвардинг пакетов"
echo "net.ipv4.ip_forward = 1" > /etc/sysctl.d/99-vpn.conf
sysctl -q --system

step "Настройки charon"
install -m 644 "$HERE/config/charon-gatekeeper.conf" /etc/strongswan.d/charon-gatekeeper.conf
# Как на исходном сервере: без libipsec (работаем через XFRM ядра) и без bypass-lan
for plugin in kernel-libipsec bypass-lan; do
    f="/etc/strongswan.d/charon/${plugin}.conf"
    [ -f "$f" ] && sed -i 's/^\(\s*\)load = yes/\1load = no/' "$f"
done

step "Сертификат Let's Encrypt для $VPN_DOMAIN"
install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
install -m 755 "$HERE/config/letsencrypt-deploy-hook.sh" /etc/letsencrypt/renewal-hooks/deploy/strongswan.sh
rm -f /etc/letsencrypt/renewal-hooks/deploy/copy-strongswan-key.sh  # хук старой установки

if [ ! -f "/etc/letsencrypt/live/$VPN_DOMAIN/fullchain.pem" ]; then
    [ -n "$LE_EMAIL" ] || { echo "Сертификата нет, нужен LE_EMAIL"; exit 1; }
    if [ "$ACME_MODE" = "webroot" ]; then
        install -d -m 755 "$ACME_WEBROOT"
        certbot certonly --webroot -w "$ACME_WEBROOT" -d "$VPN_DOMAIN" -m "$LE_EMAIL" --agree-tos -n
    else
        certbot certonly --standalone -d "$VPN_DOMAIN" -m "$LE_EMAIL" --agree-tos -n
    fi
else
    echo "    сертификат уже есть, срок: $(openssl x509 -in "/etc/letsencrypt/live/$VPN_DOMAIN/cert.pem" -noout -enddate)"
fi

# Способ продления. Когда порт 80 займёт Caddy, переключаем на webroot.
renewal="/etc/letsencrypt/renewal/$VPN_DOMAIN.conf"
if [ "$ACME_MODE" = "webroot" ] && grep -q "authenticator = standalone" "$renewal"; then
    install -d -m 755 "$ACME_WEBROOT"
    cp -a "$renewal" "$renewal.bak-$STAMP"
    sed -i "s|authenticator = standalone|authenticator = webroot\nwebroot_path = $ACME_WEBROOT,|" "$renewal"
    printf '[[webroot_map]]\n%s = %s\n' "$VPN_DOMAIN" "$ACME_WEBROOT" >> "$renewal"
    echo "    продление переключено на webroot: $ACME_WEBROOT"
fi

install -d -m 700 /etc/ipsec.d/private
install -m 600 "/etc/letsencrypt/live/$VPN_DOMAIN/privkey.pem" /etc/ipsec.d/private/privkey.pem

step "ipsec.conf"
tmp="$(mktemp)"
sed "s|\${VPN_DOMAIN}|$VPN_DOMAIN|g" "$HERE/config/ipsec.conf.template" > "$tmp"
backup_if_differs "$tmp" /etc/ipsec.conf
install -m 644 "$tmp" /etc/ipsec.conf
rm -f "$tmp"

step "ipsec.secrets"
if [ ! -f /etc/ipsec.secrets ]; then
    printf '%s : RSA "privkey.pem"\n' "$VPN_DOMAIN" > /etc/ipsec.secrets
    echo "    создан новый файл, юзеров пока нет"
elif ! grep -qE "^\s*${VPN_DOMAIN//./\\.}\s*:\s*RSA" /etc/ipsec.secrets; then
    cp -a /etc/ipsec.secrets "/etc/ipsec.secrets.bak-$STAMP"
    sed -i "1i $VPN_DOMAIN : RSA \"privkey.pem\"" /etc/ipsec.secrets
    echo "    добавлена строка с ключом сервера"
else
    echo "    юзеры не тронуты: $(grep -cE ':\s*EAP\s' /etc/ipsec.secrets) шт."
fi
chmod 600 /etc/ipsec.secrets
if grep -qE '^\s*%any\s+%any\s*:\s*PSK' /etc/ipsec.secrets; then
    echo "    ВНИМАНИЕ: в ipsec.secrets осталась строка общего PSK от L2TP. Она не нужна, удали её руками."
fi

step "Gatekeeper API"
install -d -m 755 /opt/strongswan-api
[ -f /opt/strongswan-api/api.py ] && mv /opt/strongswan-api/api.py "/opt/strongswan-api/api.py.old-$STAMP"
rm -f /opt/strongswan-api/api.py.save
install -m 755 "$HERE/api/strongswan_api.py" /opt/strongswan-api/strongswan_api.py

if [ ! -f /etc/strongswan-api.env ]; then
    sed "s|^API_KEY=.*|API_KEY=$(openssl rand -hex 32)|" "$HERE/config/strongswan-api.env.example" > /etc/strongswan-api.env
    echo "    создан /etc/strongswan-api.env с новым ключом. Положи его в .env Laravel как VPN_SECRET_KEY:"
    echo "    grep ^API_KEY /etc/strongswan-api.env"
fi
chmod 600 /etc/strongswan-api.env

backup_if_differs "$HERE/config/strongswan-api.service" /etc/systemd/system/strongswan-api.service
install -m 644 "$HERE/config/strongswan-api.service" /etc/systemd/system/strongswan-api.service

step "Файрвол для клиентов VPN"
install -m 755 "$HERE/config/vpn-firewall.sh" /usr/local/sbin/vpn-firewall.sh
install -m 644 "$HERE/config/vpn-firewall.service" /etc/systemd/system/vpn-firewall.service
mkdir -p /etc/systemd/system/vpn-firewall.service.d
printf '[Service]\nEnvironment=VPN_SUBNET=%s\n' "$VPN_SUBNET" > /etc/systemd/system/vpn-firewall.service.d/subnet.conf

step "Запуск"
systemctl daemon-reload
systemctl enable --now vpn-firewall.service
systemctl restart vpn-firewall.service

systemctl enable strongswan-starter
if systemctl is-active -q strongswan-starter; then
    # reload не рвёт установленные сессии, в отличие от restart
    ipsec reload
    ipsec rereadall
else
    systemctl start strongswan-starter
fi

systemctl enable strongswan-api
systemctl restart strongswan-api

step "Проверка"
sleep 2
systemctl is-active strongswan-starter strongswan-api vpn-firewall
socket="$(grep -E '^LISTEN=unix:' /etc/strongswan-api.env | sed 's/^LISTEN=unix://')"
if [ -n "$socket" ]; then
    curl -fsS --unix-socket "$socket" http://localhost/health && echo
fi
ipsec status | head -5

echo
echo "Готово."
