# Gatekeeper — техническая справка

Биллинг и админка VPN-сервиса. Юзеры общаются с Telegram-ботом, бот регистрирует
их, начисляет вступительный бонус, продаёт доступ, списывает поминутно (точнее —
посуточно), создаёт логин/пароль на удалённом StrongSwan IKEv2-сервере. Админка
живёт на той же платформе (Orchid Platform).

## Стек

- **Laravel 12** на **PHP 8.3**
- **Orchid Platform 14** — админка (`/admin`)
- **defstudio/telegraph 1.60** — обёртка над Telegram Bot API
- **MySQL** на стороннем хостинге (удалённое подключение)
- **Apache + PHP-FPM** на прод-сервере (через Hestia CP)
- **Caddy 2** на отдельной VPS вне РФ — reverse-proxy для входящих webhook'ов
- **Amnezia SOCKS5** на той же external VPS — proxy для исходящих к api.telegram.org

## Топология сети

```
+-------------------------+         +-----------------------+         +-------------------+
|  Telegram (мир)         | --HTTPS-->  VPS вне РФ          | --HTTPS-->  PROD (РФ)     |
|                         |            (Caddy + LE)         |            (Apache+PHP-FPM)|
|                         |            tg.<domain>          |            <domain>        |
+-------------------------+         +-----------------------+         +-------------------+
                                              ^                                |
                                              |                                |
                                              |  SOCKS5 :PORT                  |
                                              +-- proxy для исходящих <--------+
                                                  (Laravel -> api.telegram.org)
```

**Почему такая схема:** прод-сервер стоит в РФ и попадает под фильтрацию роутов
к `api.telegram.org` (РКН). И входящие от Telegram, и исходящие к Telegram режутся
интермиттентно. Внешняя VPS (в нашем случае — в стране без фильтрации) с одной
стороны принимает webhook от Telegram и форвардит к нам, с другой служит SOCKS5
прокси для наших исходящих запросов.

БД и админка и далее живут только на прод-сервере. Внешняя VPS — только сетевой
relay, никакого кода/данных там нет.

## Ключевые модели

- **`App\Models\User`** (extends Orchid User) — telegram_id, telegram_username,
  имя, referrer_id. Имеет роль `consumer` (для покупателей VPN) или платформенные
  роли (для админов).
- **`App\Models\Client`** — VPN-канал на конкретном сервере: name (логин),
  password, server_name, is_active, user_id. Один юзер может иметь несколько
  клиентов.
- **`App\Models\Transaction`** — deposit / withdraw, amount, subject_type
  (например `entry_bonus`, `vpn_service`, `yookassa`, `referral_bonus`),
  comment, is_active. Баланс юзера = `SUM(deposit) - SUM(withdraw)` по активным
  транзакциям.

## Сервисы

- **`App\Services\BinderService`** — выборка consumer'ов, подсчёт балансов,
  выборка клиентов юзера. Используется и в админке (Consumer screens), и в
  Handler'е бота.
- **`App\Services\VpnService`** — HTTP-клиент к удалённому StrongSwan-серверу.
  Принимает `server_name` в конструкторе, читает endpoint/secret из
  `config/vpn.php`. **В local-окружении сетевые вызовы заглушены**
  (`shouldExecute()` пропускает, только пишет в лог) — это позволяет
  безопасно гонять DEV-бот, не плодя пользователей на боевом StrongSwan.

## Telegram-бот

- **`App\Telegram\Handler`** (extends `DefStudio\Telegraph\Handlers\WebhookHandler`)
  — все action'ы и команды в одном классе. Главная точка — `start()`.

### Регистрация пользователя

```
start():
  if user exists -> ensureEntryBonus() + safeSend(greetExisting())
  else:
    DB::transaction:
      registerUser() + createEntryBonus()    <-- атомарно
    safeSend(greetNewcomer())
    safeSend(reply про бонус)
```

Поведение продумано на случай интермиттентных сетевых сбоев:

- **DB::transaction** — User и Entry-Bonus-транзакция коммитятся вместе. Если
  что-то упало внутри — обе записи откатываются.
- **safeSend** (private method в Handler'е) — оборачивает любой Telegram-send
  в try/catch, проглатывает Throwable, логирует warning. Это **обязательно**
  чтобы webhook вернул 200, иначе Telegram ретраит вебхук → второй заход видит
  юзера уже зарегистрированным → уходит в `greetExisting` → бонус не догоняется.
- **ensureEntryBonus** — идемпотентная страховка. Проверяет наличие транзакции
  с `subject_type='entry_bonus'`, и если нет — создаёт. Покрывает юзеров,
  зарегистрировавшихся во время сбоя (их User создан, а Transaction нет).

### Платежи

YooKassa-инвойсы через Telegram. `handlePreCheckoutQuery` подтверждает оплату,
`handleSuccessfulPayment` создаёт `deposit`-транзакцию и при наличии referrer'а
начисляет % бонус.

## Ежедневный cron — списание и сводка

- **`routes/console.php`** регистрирует `Schedule::command('vpn:daily-charge')
  ->dailyAt('10:30')` **только в `production`-окружении**.
- **`App\Console\Commands\ChargeVpnClients`** (`vpn:daily-charge`) — итерируется
  по consumer'ам с активными клиентами, считает суммарный price (из
  `config/vpn.php` по `server_name`), создаёт `withdraw`-транзакцию. После
  списания: если баланс < 0 — деактивирует все активные клиенты через
  `VpnService::deactivateClient`. Если ≥ 0 — реактивирует ранее заблокированных.
- **Уведомления:** per-user предупреждение если осталось < 7 дней или баланс
  ушёл в минус. В конце прогона — Telegram-сводка админу + email
  (`ClientsBlocked` если кого-то заблокировали, `DailySummary` всегда).
- **`--force-notify`** — флаг для тестов, шлёт уведомления независимо от
  условий.
- **`--user-ids=1,2,3`** — обработать только конкретных consumer'ов.

## Sending Telegram-сообщений: ретраи и прокси

### Прокси (App\Support\TelegramProxy)

`config/telegram.php` читает `TELEGRAM_PROXY_*` env-переменные. Когда
`TELEGRAM_PROXY_ENABLED=true`, `AppServiceProvider::boot()` вызывает
`TelegramProxy::applyGlobal()`, который через `Http::globalOptions([...])`
прописывает Guzzle-опцию `proxy.https`. Все исходящие HTTPS-запросы Laravel
(включая Telegraph send()) идут через указанный SOCKS5/HTTP прокси.

VPN-сервер (HTTP, не HTTPS) запросы НЕ затрагиваются. Если нужно ещё что-то
исключить — `TELEGRAM_PROXY_NO_HOSTS` (comma-separated).

### Ретраи (App\Support\TelegraphRetry)

Helper-класс с методом `attempt(callable, attempts, delayMs, context)`.
Стратегия по умолчанию (5 попыток, 500ms между):

- **proxy disabled** → все 5 попыток direct
- **proxy enabled, fallback off** → все 5 через прокси
- **proxy enabled, fallback on** → попытки 1-3 через прокси, 4-5 direct
  (на случай если прокси упал)

Используется только в `ChargeVpnClients` (daily cron). В `Handler` (бот)
ретраев нет — там `safeSend` (try/catch без retry), потому что webhook нельзя
долго удерживать (Telegram ретраит сам).

## ENV переменные

Документированы в `.env.example`. Боевой `.env` в `.gitignore`. Ключевые:

```
APP_ENV=production         # обязательно production, иначе schedule в console.php не зарегистрируется
DB_*                       # удалённая MySQL
LOG_CHANNEL=stack
LOG_STACK=daily            # daily ротация на 14 дней (см. config/logging.php)
LOG_LEVEL=error            # boost до warning/debug для отладки

TELEGRAPH_BOT_TOKEN=       # боевой бот (от @BotFather)
TELEGRAPH_BOT_USERNAME=
TELEGRAPH_BOT_NOTIFY_ID=   # id записи telegraph_bots в БД, используется для уведомлений
TELEGRAPH_PAYMENT_PROVIDER_TOKEN=   # YooKassa
ADMIN_CHAT_ID=             # telegram_id админа для админских уведомлений
ADMIN_EMAIL=               # для DailySummary / ClientsBlocked

VPN_SECRET_KEY=            # auth к StrongSwan-серверу

# SOCKS5/HTTP прокси к api.telegram.org (когда исходящие режутся)
TELEGRAM_PROXY_ENABLED=true
TELEGRAM_PROXY_URL=socks5h://user:pass@host:port
TELEGRAM_PROXY_FALLBACK_DIRECT=true
TELEGRAM_PROXY_NO_HOSTS=
```

## Деплой

```bash
cd /path/to/public_html
git pull
# если изменились .env / config — обязательно:
php artisan config:clear
# если изменились миграции:
php artisan migrate
# composer install — только если изменились зависимости
```

Никаких артефактов в репо не лежит (vendor/, node_modules/, .env — в gitignore).

## Внешняя VPS (Caddy + SOCKS5)

### Caddy reverse-proxy

`/etc/caddy/Caddyfile`:

```caddyfile
{
    email some@email.example
}

tg.<domain> {
    @telegraph path /telegraph/*

    handle @telegraph {
        reverse_proxy https://<prod-domain> {
            header_up Host <prod-domain>
            transport http {
                tls
                tls_server_name <prod-domain>
            }
        }
    }

    handle {
        respond "Not Found" 404
    }

    log {
        output file /var/log/caddy/access.log
        format console
    }
}
```

Только `/telegraph/*` форвардится — остальные пути закрыты 404. Так Caddy
не светит наружу всё что есть на проде.

`email` в глобал-блоке — **обязательно**, иначе Caddy при каждом restart
регистрирует новый ACME-аккаунт, и довольно быстро упирается в Let's Encrypt
rate limit (10 регистраций / 3 часа / IP).

### Webhook у Telegram

Webhook URL = `https://tg.<domain>/telegraph/<BOT_TOKEN>/webhook`. Устанавливать
через прокси (потому что direct с прода может быть мёртв):

```bash
BOT_TOKEN=$(grep ^TELEGRAPH_BOT_TOKEN .env | cut -d= -f2 | tr -d '"')
PROXY="socks5h://..."
curl -x "$PROXY" -m 20 -s \
  "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://tg.<domain>/telegraph/${BOT_TOKEN}/webhook"
curl -x "$PROXY" -m 20 -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
```

### SOCKS5 для исходящих

Поднимается на этой же VPS через Amnezia (или другой соответствующий софт).
Прокидывает TCP-порт, который потом прописывается в `TELEGRAM_PROXY_URL`
на проде.

## Тонкости которые легко забыть

- **DNS у некоторых хостеров подменяет:** на прод-сервере локальный DNS
  resolver хостинга может возвращать "свой" IP для свежедобавленного
  поддомена. Для проверки реального резолва — `dig @8.8.8.8 <domain> +short`.
  Для curl-тестов с прода — `--resolve <domain>:443:<real_ip>`.
- **.su и другие "редкие" TLD:** ZeroSSL и некоторые другие ACME-провайдеры
  не выдают cert на free плане. Let's Encrypt и Buypass — выдают.
- **Let's Encrypt rate limit "10 регистраций / 3 часа / IP":** очень легко
  упереться при отладке Caddy, если каждый раз чистить ACME-state. Решение —
  **не чистить**, и обязательно `email` в Caddyfile для persistent account'а.
- **`schedule:run` только в production:** на DEV cron не зарегистрирован
  (см. `routes/console.php`). Для отладки cron'а руками:
  `php artisan vpn:daily-charge --force-notify`.
- **`subject_type='entry_bonus'`** — единственный надёжный маркер вступительного
  бонуса. Сравнение по `comment` ненадёжно (комменты на русском, в т.ч. с
  опечатками). `ensureEntryBonus` смотрит именно на `subject_type`.
- **Логи:** на проде `LOG_STACK=daily`, файлы `storage/logs/laravel-YYYY-MM-DD.log`,
  хранятся 14 дней (`LOG_DAILY_DAYS`). Старый монолитный `laravel.log` мог
  остаться — можно безопасно удалить.
- **Логи Apache** (через Hestia): `~/web/<domain>/logs/<domain>.log` и
  `<domain>.error.log` — симлинки на `/var/log/apache2/domains/`.
- **Скрытый "двойной бонус":** consumer'ы, которым админ накатил Entry Bonus
  через Orchid вручную (с `subject_type=null`), при следующем `/start`
  получат **ещё один** бонус через `ensureEntryBonus`. Если это нежелательно
  — поправить `subject_type` в БД на `entry_bonus` для таких записей.

## Откаты / kill switch

- **Отключить прокси:**
  `sed -i 's/^TELEGRAM_PROXY_ENABLED=.*/TELEGRAM_PROXY_ENABLED=false/' .env && php artisan config:clear`.
  Сразу возвращаемся к direct'у (если он работает).
- **Вернуть webhook на прямой URL:**
  `curl -x "$PROXY" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://<prod-domain>/telegraph/${BOT_TOKEN}/webhook"`.
- **Удалить webhook совсем:** `?url=` (пустой).

## История ключевых фиксов

- Try/catch в `vpn:daily-charge` чтобы одна сломанная отправка не валила весь
  прогон с exit code 1.
- Daily-ротация логов вместо одного бесконечно растущего файла.
- Атомарная регистрация User + Entry Bonus в `DB::transaction`,
  `safeSend` в `Handler::start`, идемпотентный `ensureEntryBonus`.
- `App\Support\TelegraphRetry` с proxy-first / direct-fallback стратегией.
- `App\Support\TelegramProxy` + `TELEGRAM_PROXY_*` env для роутинга исходящих
  через SOCKS5 на не-РФ VPS.
- Caddy на не-РФ VPS как reverse-proxy для входящих webhook'ов от Telegram.

## Полезные команды

```bash
# Список зарегистрированных scheduled-команд
php artisan schedule:list

# Прогон daily-charge руками
php artisan vpn:daily-charge --force-notify

# Конкретные юзеры
php artisan vpn:daily-charge --user-ids=42,43

# Какие в БД боты telegraph_bots / чаты telegraph_chats
php artisan tinker --execute="\DefStudio\Telegraph\Models\TelegraphBot::all()->each(fn(\$b) => print \$b->id.' '.\$b->name.PHP_EOL);"

# Сбросить кэш конфига после правки .env
php artisan config:clear

# Полная очистка всех Laravel-кэшей
php artisan optimize:clear

# Свежесть лога
ls -la storage/logs/

# Что Telegram думает про наш webhook
curl -x "$PROXY" "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
```
