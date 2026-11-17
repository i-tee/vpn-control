#!/bin/bash
# /usr/local/sbin/vpn-firewall.sh — правила для трафика VPN-клиентов.
#
# Идемпотентно: правило добавляется, только если его ещё нет.
# Запускается юнитом vpn-firewall.service ПОСЛЕ docker.service.
#
# Почему не iptables-persistent: Docker при старте ставит политику FORWARD
# в DROP. Старые правила сервера ловили интерфейс "charon", которого нет
# (kernel-libipsec выключен, трафик идёт через XFRM на eth0), и VPN работал
# только благодаря политике ACCEPT. С Docker на том же хосте клиенты потеряли
# бы интернет. Здесь правила привязаны к подсети клиентов и IPsec-политике.
set -euo pipefail

VPN_SUBNET="${VPN_SUBNET:-10.250.0.0/16}"
WAN_IF="${WAN_IF:-$(ip -4 route get 1.1.1.1 | awk '{for (i = 1; i < NF; i++) if ($i == "dev") print $(i + 1)}')}"

ensure() {
    local table="$1"; shift
    local chain="$1"; shift
    iptables -t "$table" -C "$chain" "$@" 2>/dev/null || iptables -t "$table" -I "$chain" 1 "$@"
}

# Клиенты выходят в интернет из туннеля
ensure filter FORWARD -s "$VPN_SUBNET" -m policy --dir in --pol ipsec -j ACCEPT
# Ответы возвращаются клиентам в туннель
ensure filter FORWARD -d "$VPN_SUBNET" -m policy --dir out --pol ipsec -j ACCEPT
# Подмена адреса клиентов на адрес сервера, кроме трафика, который уходит обратно в IPsec
ensure nat POSTROUTING -s "$VPN_SUBNET" -o "$WAN_IF" -m policy --dir out --pol none -j MASQUERADE

echo "vpn-firewall: rules ensured for $VPN_SUBNET via $WAN_IF"
