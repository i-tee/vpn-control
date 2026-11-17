#!/bin/bash
# Первичная настройка сервера под Gatekeeper (Ubuntu 22.04/24.04). Один раз, от root.
#
#   sudo DEPLOY_PUBKEY="ssh-ed25519 AAAA... github-actions" ./infra/app/setup-server.sh
#
# ВАЖНО на сервере с живым VPN: сначала infra/strongswan/install.sh (он ставит
# vpn-firewall), потом этот скрипт. Docker при первом старте ставит политику
# FORWARD в DROP, и без правил vpn-firewall клиенты VPN потеряют интернет.
#
# Что делает (идемпотентно):
#   * ставит Docker Engine из официального репозитория и sqlite3;
#   * создаёт пользователя deploy в группе docker;
#   * кладёт ключ деплоя с принудительной командой deploy.sh;
#   * создаёт /opt/gatekeeper со storage, caddy, backups, releases;
#   * кладёт шаблон .env, если его ещё нет.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOME_DIR=/opt/gatekeeper
DEPLOY_USER=deploy
APP_UID=33   # www-data в образе

[ "$(id -u)" -eq 0 ] || { echo "Запускать от root"; exit 1; }
step() { echo; echo "==> $*"; }

if ! systemctl is-enabled -q vpn-firewall 2>/dev/null; then
    echo "vpn-firewall не установлен. Сначала infra/strongswan/install.sh, иначе Docker отрежет VPN-клиентов от интернета."
    [ "${FORCE:-}" = "1" ] || exit 1
fi

step "Docker Engine"
if ! command -v docker >/dev/null; then
    apt-get update -q
    apt-get install -y -q ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -q
    apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi
mkdir -p /etc/docker
[ -f /etc/docker/daemon.json ] || cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
JSON
systemctl enable --now docker
# Docker только что выставил FORWARD DROP — возвращаем правила VPN поверх
systemctl restart vpn-firewall 2>/dev/null || true

step "sqlite3 для копий базы"
apt-get install -y -q sqlite3

step "Пользователь $DEPLOY_USER"
id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"

if [ -n "${DEPLOY_PUBKEY:-}" ]; then
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    keys="/home/$DEPLOY_USER/.ssh/authorized_keys"
    line="command=\"$HOME_DIR/bin/deploy.sh\",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty $DEPLOY_PUBKEY"
    touch "$keys"
    grep -qF "$DEPLOY_PUBKEY" "$keys" || echo "$line" >> "$keys"
    chown "$DEPLOY_USER:$DEPLOY_USER" "$keys"
    chmod 600 "$keys"
    echo "    ключ деплоя добавлен (может только запускать deploy.sh)"
else
    echo "    DEPLOY_PUBKEY не задан, ключ не добавлен"
fi

step "Каталоги $HOME_DIR"
install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$HOME_DIR" "$HOME_DIR/bin" "$HOME_DIR/releases" "$HOME_DIR/backups"
# storage и caddy пишет приложение (www-data, uid 33)
install -d -m 755 -o "$APP_UID" -g "$APP_UID" "$HOME_DIR/storage" "$HOME_DIR/storage/database" \
    "$HOME_DIR/caddy" "$HOME_DIR/caddy/data" "$HOME_DIR/caddy/config"
install -d -m 755 /var/www/acme
install -m 755 "$HERE/deploy.sh" "$HOME_DIR/bin/deploy.sh"

if [ ! -f "$HOME_DIR/.env" ]; then
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$HERE/env.production.example" "$HOME_DIR/.env"
    echo "    положен шаблон $HOME_DIR/.env — заполни его до первого деплоя"
fi

step "Готово"
echo "Дальше:"
echo "  1. заполнить $HOME_DIR/.env"
echo "  2. в GitHub: секреты DEPLOY_HOST, DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS и переменная DEPLOY_ENABLED=true"
echo "  3. push в main — GitHub Actions соберёт образ и задеплоит"
