#!/bin/bash
# Деплой Gatekeeper на сервере. Ставится в /opt/gatekeeper/bin/deploy.sh.
#
# Вызывается из GitHub Actions по SSH. Ключ деплоя в authorized_keys привязан
# к этому скрипту (command="..."), поэтому по нему нельзя выполнить ничего другого.
# Команда приходит в SSH_ORIGINAL_COMMAND, образ — сжатым через stdin:
#
#   docker save gatekeeper:<sha> | gzip | ssh deploy@host "deploy <sha>"
#   ssh deploy@host "rollback"     # вернуть предыдущую версию
#   ssh deploy@host "status"       # что сейчас запущено
#
# Шаги деплоя: загрузить образ → копия базы → миграции → перезапуск →
# проверка здоровья → при провале автоматический откат на предыдущую версию.
set -Eeuo pipefail

HOME_DIR="${GATEKEEPER_HOME:-/opt/gatekeeper}"
cd "$HOME_DIR"

COMPOSE=(docker compose -f docker-compose.yml)
[ -f docker-compose.override.yml ] && COMPOSE+=(-f docker-compose.override.yml)
DB="storage/database/database.sqlite"
KEEP_IMAGES=5
KEEP_BACKUPS=20

log() { echo "[deploy $(date -u +%H:%M:%S)] $*"; }
die() { log "ERROR: $*"; exit 1; }
# set -e роняет скрипт молча — пусть хотя бы скажет, где
trap 'log "ERROR: упала команда на строке $LINENO (код $?)"' ERR

env_value() {
    grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

current_tag() { cat releases/current 2>/dev/null || true; }
previous_tag() { cat releases/previous 2>/dev/null || true; }

backup_db() {
    # backup_db <метка> <образ>
    [ -f "$DB" ] || return 0
    mkdir -p backups
    local name
    name="db-$(date -u +%Y%m%d-%H%M%S)-$1.sqlite"
    # Внутри контейнера и от root: база в режиме WAL, читать её можно только
    # с правом записи в служебный -shm файл, а он принадлежит www-data (uid 33).
    # .backup безопасен при работающем приложении, в отличие от cp.
    docker run --rm -u 0 \
        -v "$HOME_DIR/storage:/app/storage" -v "$HOME_DIR/backups:/backups" \
        --entrypoint sh "$2" -c \
        "sqlite3 /app/storage/database/database.sqlite \".backup '/backups/$name'\" \
         && gzip '/backups/$name' && chown $(id -u):$(id -g) '/backups/$name.gz'"
    ls -1t backups/db-*.sqlite.gz | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
    log "копия базы: backups/$name.gz"
}

healthy() {
    local domain url
    domain="$(env_value APP_DOMAIN)"
    url="${HEALTH_URL:-https://$domain/up}"
    for _ in $(seq 1 30); do
        if [ "$("${COMPOSE[@]}" ps --status running -q app scheduler | wc -l)" -eq 2 ] \
            && curl -fsk --max-time 5 --resolve "$domain:443:127.0.0.1" "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    return 1
}

switch_to() {
    docker tag "gatekeeper:$1" gatekeeper:current
    "${COMPOSE[@]}" up -d --force-recreate --remove-orphans
}

prune_images() {
    local keep
    keep="$(printf '%s\n%s\n' "$(current_tag)" "$(previous_tag)")"
    docker images gatekeeper --format '{{.Tag}}' \
        | grep -vxE 'current|<none>' \
        | grep -vxF "$keep" \
        | tail -n +$((KEEP_IMAGES - 1)) \
        | xargs -r -I{} docker rmi "gatekeeper:{}" >/dev/null 2>&1 || true
}

cmd_deploy() {
    local tag="$1"
    [[ "$tag" =~ ^[0-9a-f]{7,40}$ ]] || die "тег должен быть sha коммита, получено: '$tag'"
    [ -f .env ] || die ".env не найден в $HOME_DIR"
    mkdir -p releases

    log "загружаю образ gatekeeper:$tag"
    gunzip | docker load -q
    docker image inspect "gatekeeper:$tag" >/dev/null 2>&1 || die "в загруженном образе нет тега gatekeeper:$tag"

    # compose-файл всегда из той же версии, что и код
    docker run --rm --entrypoint cat "gatekeeper:$tag" /app/infra/app/docker-compose.prod.yml > docker-compose.yml.new
    mv docker-compose.yml.new docker-compose.yml

    backup_db "pre-$tag" "gatekeeper:$tag"

    log "миграции"
    docker run --rm --env-file .env -v "$HOME_DIR/storage:/app/storage" \
        "gatekeeper:$tag" php artisan migrate --force

    local prev
    prev="$(current_tag)"
    log "переключаю ${prev:-ничего} -> $tag"
    switch_to "$tag"

    if healthy; then
        [ -n "$prev" ] && [ "$prev" != "$tag" ] && echo "$prev" > releases/previous
        echo "$tag" > releases/current
        echo "$(date -u +%FT%TZ) $tag" >> releases/history
        prune_images
        log "готово: $tag"
        return 0
    fi

    log "новая версия не поднялась"
    "${COMPOSE[@]}" logs --tail 40 app scheduler || true
    if [ -n "$prev" ] && [ "$prev" != "$tag" ]; then
        log "откатываюсь на $prev"
        switch_to "$prev"
        healthy && log "откат выполнен, работает $prev" || log "ВНИМАНИЕ: после отката проверка тоже не прошла"
    fi
    die "деплой $tag не удался"
}

cmd_rollback() {
    local prev cur
    prev="$(previous_tag)"
    cur="$(current_tag)"
    [ -n "$prev" ] || die "предыдущей версии нет"
    log "откат $cur -> $prev"
    switch_to "$prev"
    healthy || die "после отката проверка не прошла"
    echo "$cur" > releases/previous
    echo "$prev" > releases/current
    echo "$(date -u +%FT%TZ) $prev (rollback)" >> releases/history
    log "готово: $prev"
}

cmd_status() {
    echo "current:  $(current_tag)"
    echo "previous: $(previous_tag)"
    "${COMPOSE[@]}" ps
    tail -5 releases/history 2>/dev/null || true
}

read -r ACTION ARG _ <<< "${SSH_ORIGINAL_COMMAND:-$*}"
case "${ACTION:-}" in
    deploy)   cmd_deploy "${ARG:-}" ;;
    rollback) cmd_rollback ;;
    status)   cmd_status ;;
    *)        die "неизвестная команда: '${ACTION:-}'. Можно: deploy <sha>, rollback, status" ;;
esac
