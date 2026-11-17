#!/bin/sh
# Подготовка при старте контейнера: каталоги в storage (он смонтирован с хоста
# и на свежем сервере пуст), файл SQLite, кеши Laravel по текущему окружению.
set -e
cd /app

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs "$(dirname "$DB_DATABASE")"
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY не задан. Сгенерируй: docker run --rm --entrypoint php <образ> artisan key:generate --show" >&2
    exit 1
fi

# config:cache безопасен: env() вне config/ в коде нет
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet
php artisan event:cache --quiet

exec "$@"
