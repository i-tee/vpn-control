# Образ Gatekeeper: FrankenPHP (PHP 8.3 + встроенный Caddy).
#
# Прод (стадия по умолчанию, её собирает CI):
#   docker build -f infra/app/Dockerfile -t gatekeeper:local .
# Локальная разработка (код подключается папкой, см. docker-compose.yml в корне):
#   docker build -f infra/app/Dockerfile --target dev .

# --- общая база: PHP-расширения и настройки ----------------------------------
FROM dunglas/frankenphp:1.12.7-php8.3-bookworm AS base

RUN install-php-extensions pdo_sqlite intl zip bcmath opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY infra/app/php.ini "$PHP_INI_DIR/conf.d/zz-gatekeeper.ini"
WORKDIR /app

# --- локальная разработка ------------------------------------------------------
FROM base AS dev

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    # код меняется на лету — opcache должен перечитывать файлы.
    # zzz-: конфиги читаются по алфавиту, этот должен идти после zz-gatekeeper.ini
    && printf 'opcache.validate_timestamps = 1\nopcache.revalidate_freq = 0\n' > "$PHP_INI_DIR/conf.d/zzz-dev.ini" \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

COPY infra/app/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

# Контейнер работает от uid хоста (docker-compose: user), поэтому служебные
# каталоги Caddy и composer — во временной папке, куда может писать любой.
ENV XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data \
    HOME=/tmp \
    COMPOSER_HOME=/tmp/composer

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile.dev"]

# --- зависимости composer для прода --------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize

# --- прод (последняя стадия = стадия по умолчанию) -----------------------------
FROM base AS runtime

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY infra/app/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 infra/app/entrypoint.sh /usr/local/bin/gatekeeper-entrypoint
COPY --from=vendor /app /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=daily \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database/database.sqlite \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# package:discover пропущен в composer-стадии (--no-scripts)
RUN php artisan package:discover --ansi \
    && rm -rf tests node_modules .git storage/logs/* storage/framework/cache/data/* \
    && mkdir -p /var/www/acme /data /config \
    # Работаем от www-data (uid/gid 33): этой группе VPN API отдаёт свой сокет.
    # setcap позволяет слушать 80/443 без root.
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data

ENTRYPOINT ["gatekeeper-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
