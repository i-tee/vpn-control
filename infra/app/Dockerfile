# Продовый образ Gatekeeper: FrankenPHP (PHP 8.3 + встроенный Caddy с авто-HTTPS).
# Сборка из корня репозитория:
#   docker build -f infra/app/Dockerfile -t gatekeeper:local .

# --- зависимости composer -----------------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize

# --- рантайм ------------------------------------------------------------------
FROM dunglas/frankenphp:1.12.7-php8.3-bookworm

RUN install-php-extensions pdo_sqlite intl zip bcmath opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY infra/app/php.ini "$PHP_INI_DIR/conf.d/zz-gatekeeper.ini"
COPY infra/app/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 infra/app/entrypoint.sh /usr/local/bin/gatekeeper-entrypoint

WORKDIR /app
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
    && mkdir -p /var/www/acme \
    # Работаем от www-data (uid/gid 33): этой группе VPN API отдаёт свой сокет.
    # setcap позволяет слушать 80/443 без root.
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data

ENTRYPOINT ["gatekeeper-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
