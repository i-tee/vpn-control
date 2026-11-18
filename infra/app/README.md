# Прод: Docker и автодеплой

Laravel работает в Docker на том же сервере, что и StrongSwan. Push в `main`
→ GitHub Actions гоняет тесты → собирает образ → по SSH отдаёт его серверу →
сервер делает копию базы, миграции, перезапуск и проверку. Если новая версия
не поднялась, сервер сам возвращает предыдущую.

## Из чего состоит

| Файл | Что это |
|---|---|
| `Dockerfile` | образ на FrankenPHP (PHP 8.3 + Caddy с авто-HTTPS), работает от www-data |
| `Caddyfile` | HTTPS для `APP_DOMAIN`; на `VPN_DOMAIN` по HTTP только проверка Let's Encrypt для сертификата StrongSwan |
| `entrypoint.sh` | при старте: каталоги в storage, файл SQLite, кеши config/route/view/event |
| `docker-compose.prod.yml` | два контейнера из одного образа: `app` (веб) и `scheduler` (`schedule:work`) |
| `deploy.sh` | деплой, откат и статус на сервере |
| `setup-server.sh` | первичная настройка сервера |
| `env.production.example` | шаблон `/opt/gatekeeper/.env` |
| `../../.github/workflows/deploy.yml` | тесты и деплой |

Почему FrankenPHP: один контейнер заменяет nginx, PHP-FPM и Caddy. На сервере
1 ГБ памяти, контейнер `app` занимает около 60 МБ.

Почему `network_mode: host`: приложение слушает 80/443 прямо на хосте, без
NAT Docker; трафик VPN идёт мимо Docker вообще.

## На сервере

```
/opt/gatekeeper/
  .env                    продовые настройки (600, deploy)
  docker-compose.yml      обновляется из образа при каждом деплое
  bin/deploy.sh           принудительная команда ключа деплоя
  storage/                storage Laravel, владелец uid 33
    database/database.sqlite
    logs/
  caddy/data, caddy/config  сертификаты Caddy
  backups/                копии базы перед каждым деплоем (последние 20)
  releases/current, previous, history
/var/www/acme             webroot для продления сертификата StrongSwan
/run/strongswan-api/      сокет VPN API, смонтирован в контейнеры
```

## Первый запуск

Порядок важен на сервере с живым VPN:

1. `infra/strongswan/install.sh` — ставит vpn-firewall. Без него Docker при
   первом старте отрежет VPN-клиентов от интернета.
2. Ключ для GitHub Actions: `ssh-keygen -t ed25519 -f gatekeeper_deploy -N ""`.
3. `sudo DEPLOY_PUBKEY="$(cat gatekeeper_deploy.pub)" ./infra/app/setup-server.sh`.
4. Заполнить `/opt/gatekeeper/.env`. `APP_KEY` сгенерировать любой копией образа
   или локально: `php artisan key:generate --show`.
5. В GitHub, Settings → Secrets and variables → Actions:
   - секреты `DEPLOY_HOST`, `DEPLOY_SSH_KEY` (содержимое `gatekeeper_deploy`),
     `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -t ed25519 <host>`);
   - переменная `DEPLOY_ENABLED=true`.
6. Push в `main` или «Run workflow» вручную.
7. После первого деплоя:
   ```bash
   cd /opt/gatekeeper
   docker compose exec app php artisan orchid:admin <name> <email> <password>
   docker compose exec app php artisan telegraph:new-bot
   docker compose exec app php artisan telegraph:set-webhook 1 --secret=<TELEGRAPH_WEBHOOK_SECRET из .env>
   ```

## Повседневное

```bash
# что запущено
ssh deploy@<host> status
# вернуть предыдущую версию
ssh deploy@<host> rollback

# от root на сервере
cd /opt/gatekeeper
docker compose ps
docker compose logs -f --tail 100 app
docker compose exec app php artisan tinker
docker compose exec app php artisan vpn:reconcile
tail -f storage/logs/laravel-$(date +%F).log
```

Восстановить базу из копии:

```bash
cd /opt/gatekeeper
docker compose stop app scheduler
# база в режиме WAL: старые -wal/-shm от прошлой базы нельзя оставлять рядом с копией
rm -f storage/database/database.sqlite-wal storage/database/database.sqlite-shm
gunzip -c backups/db-<дата>-pre-<sha>.sqlite.gz > storage/database/database.sqlite
chown 33:33 storage/database/database.sqlite
docker compose start app scheduler
```

## Грабли, на которые уже наступили

- **`docker compose exec -T` читает stdin.** Если скрипт передан в
  `ssh host bash` через stdin, первый же `exec` съест остаток скрипта. Либо
  копировать скрипт файлом, либо `docker compose exec -T app ... </dev/null`.
- **Копия базы — только изнутри контейнера.** В режиме WAL читать базу можно
  лишь с правом записи в `-shm` файл, а он принадлежит www-data. Поэтому
  `deploy.sh` снимает `.backup` через `docker run -u 0` из нового образа.
- **Деплой при плохой связи.** Если SSH оборвался во время загрузки образа,
  образ мог успеть загрузиться. Тогда деплой можно запустить на самом сервере,
  без повторной заливки и не завися от связи:
  ```bash
  TAG=<sha>
  setsid nohup bash -c "docker save gatekeeper:$TAG | gzip -1 | sudo -u deploy /opt/gatekeeper/bin/deploy.sh deploy $TAG" \
      > /tmp/deploy-$TAG.log 2>&1 </dev/null &
  ```
- **DNS хостера.** 17.11.2026 резолвер Timeweb минут 20 отвечал через раз, и
  крон не разослал уведомления о балансе. Теперь рядом стоят публичные
  резолверы (`setup-server.sh`, шаг «Запасные DNS»).
- **Встроенный healthcheck FrankenPHP** ходит в админ-API Caddy, а он
  выключен. В compose проверка заменена на `/up`.

## Откат и миграции

Откат меняет только код. Миграции назад не откатываются: новая колонка
останется, старый код её просто не заметит. Если миграция ломает данные,
восстанавливай базу из копии `pre-<sha>`, снятой прямо перед тем деплоем.

## Проверено локально

- образ собирается, отвечает `/up`, `/help`, `/admin/login`, работает от
  www-data, в планировщике видны списание и сверка;
- `deploy.sh`: первый деплой с миграциями, второй с копией базы, сломанная
  версия откатывается автоматически, ручной откат, мусор вместо sha отклоняется.
