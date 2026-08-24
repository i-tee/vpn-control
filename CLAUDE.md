# Gatekeeper — техническая справка

Биллинг и админка VPN-сервиса. Юзеры общаются с Telegram-ботом, бот регистрирует
их, начисляет вступительный бонус, продаёт доступ, списывает посуточно, создаёт
логин/пароль на удалённом StrongSwan IKEv2-сервере. Админка живёт на той же
платформе (Orchid Platform).

## Стек

- **Laravel 12** на **PHP 8.3**
- **Orchid Platform 14** — админка (`/admin`)
- **defstudio/telegraph 1.60** — обёртка над Telegram Bot API
- **MySQL** на стороннем хостинге (удалённое подключение)
- **Apache + PHP-FPM** на прод-сервере (через Hestia CP)
- **Caddy 2** на VPS вне РФ — reverse-proxy для входящих webhook'ов
- **Amnezia SOCKS5** на той же VPS — proxy для исходящих к api.telegram.org

`Dockerfile`, `docker-compose.yml`, `nginx/` — только для локальной разработки,
прод работает без них.

**Локальные порты** (разведены с checklist-expert, который держит 80/3306/1025/8025):

| Сервис | Порт на хосте | Переменная |
|---|---|---|
| nginx | **8080** (на него смотрит ngrok) | `HTTP_PORT` |
| mailpit UI | **8125** | `MAILPIT_UI_PORT` |
| mailpit SMTP | **1125** | `MAILPIT_SMTP_PORT` |
| redis | **6479** | `REDIS_PORT_HOST` |

Внутри docker-сети порты стандартные (`mailpit:1025`, `redis:6379`) — приложение
ходит к контейнерам по именам, поэтому `MAIL_PORT`/`REDIS_PORT` в `.env` менять
не нужно.

## Топология сети

Одна внешняя VPS выполняет **обе** роли: принимает webhook от Telegram (Caddy)
и служит SOCKS5-прокси для наших исходящих (Amnezia).

```
+-------------------+         +--------------------------+         +------------------+
| Telegram (мир)    | --POST-->  VPS вне РФ              | --HTTPS-->  PROD (РФ)      |
|                   |           tg.<domain> :443 Caddy   |            <domain>        |
|                   |           SOCKS5    :PORT Amnezia  |            Apache+PHP-FPM  |
+-------------------+         +--------------------------+         +------------------+
                                          ^                                |
                                          +-- проксирует исходящие <-------+
                                              (Laravel -> api.telegram.org)
```

**Почему так:** прод стоит в РФ и попадает под фильтрацию роутов к
`api.telegram.org`. Режется в обе стороны — и входящие webhook, и исходящие
запросы. Внешняя VPS решает обе проблемы сразу.

БД, код и админка живут только на проде. Внешняя VPS — чистый сетевой relay,
никакого кода/данных там нет.

## Ключевые модели

- **`App\Models\User`** (extends Orchid User) — telegram_id, telegram_username,
  имя, referrer_id. Роль `consumer` (покупатели VPN) или платформенные (админы).
  Содержит слой статических telegram-фасадов: `getIdByTelegramId`,
  `getClientsByTelegramId`, `getBalanceByTelegramId`, `creatOneClientFromTelegram`.
  ⚠️ Баланс считается **двумя** способами вперемешку: метод `balance()` (raw SQL)
  и аксессор `getBalanceAttribute()` (через BinderService). Плюс скоуп
  `scopeWithBalance()` для сортировки в списках.
- **`App\Models\Client`** — VPN-канал на сервере: name (логин), password
  (открытым текстом), server_name, is_active, user_id.
- **`App\Models\Transaction`** — deposit / withdraw, amount, subject_type
  (`entry_bonus`, `vpn_service`, `yookassa`, `referral_bonus` или **null** при
  ручном создании через админку), comment, is_active.
  Баланс = `SUM(deposit) - SUM(withdraw)` по активным транзакциям.

### Схема БД и её расхождение с миграциями

```
users         id, name, email, password, permissions(jsonb),
              telegram_id(unique), telegram_first_name, telegram_last_name,
              telegram_username(unique), referrer_id(FK users, nullOnDelete)
clients       id, name, password, user_id(FK cascade), server_name,
              owner_id, telegram_nickname, is_active(*), timestamps
transactions  id, user_id(FK cascade), type ENUM(deposit|withdraw),
              amount DECIMAL(10,2), subject_type, subject_id,
              comment TEXT, is_active BOOL default true, timestamps
```

⚠️ **`clients.is_active` не создаётся ни одной миграцией** — колонку добавили
руками прямо на проде. Поле используется везде (модель, обсерверы, VpnService,
крон, все экраны админки), но `php artisan migrate` на чистой БД даст нерабочее
приложение. Если поднимаешь окружение с нуля — колонку нужно добавить вручную
или дописать миграцию.

⚠️ **Индексов нет** кроме автоматических (FK + unique на telegram-полях). При
этом баланс считается агрегатом по `transactions` для каждого consumer'а на
каждом экране и в каждом прогоне крона. На текущих объёмах (десятки юзеров) не
болит, но при росте — первое место, куда смотреть.

⚠️ `clients.price` нет — цена всегда из `config/vpn.php` по `server_name`.

## Тарификация — единый центр

Вся цена определяется **только** в `config/vpn.php`:

```php
'default_price' => 20,  // цена за клиент в сутки — единственный источник правды
'entry_bonus'   => 100, // вступительный бонус (при цене 20 = 5 дней теста)
```

У сервера в `servers[]` ключ `price` указывается **только если цена отличается**
от базовой. Нет ключа — наследует `default_price`.

Все расчёты идут через **`App\Support\Pricing`**, напрямую `config('vpn.…')`
для цен читать не надо:

| Метод | Что делает |
|---|---|
| `Pricing::default()` | базовая цена за клиент/сутки |
| `Pricing::forServer($name)` | цена сервера, с фолбэком на базовую |
| `Pricing::entryBonus()` | вступительный бонус |
| `Pricing::freeDays()` | сколько дней теста даёт бонус (для `{days}` в тексте) |
| `Pricing::dailyCostForUser($user)` | суточный расход юзера = сумма по его активным клиентам |
| `Pricing::daysLeft($balance, $dailyCost)` | сколько полных суток проживёт баланс |

⚠️ **Цена в `servers[]` читается через индекс массива, а не через
`config("vpn.servers.{$name}.price")`** — имена серверов содержат точки
(`x.xab.su`), а `config()` трактует точку как разделитель уровней и такой ключ
просто не найдёт. Легко наступить повторно.

Чтобы поднять цену — меняется **одно число** `default_price`. Автоматически
подтянутся: списание в кроне, «Расход/Ещё дней» в боте, текст приветствия
(`{price}`, `{days}`), пороги уведомлений.

## Сервисы

- **`App\Services\BinderService`** — выборка consumer'ов, подсчёт балансов,
  выборка клиентов юзера. Используется и в админке, и в Handler'е бота.
- **`App\Services\VpnService`** — HTTP-клиент к StrongSwan-серверу. Принимает
  `server_name` в конструкторе, endpoint/secret из `config/vpn.php`.
  **В не-production окружении сетевые вызовы заглушены** (`shouldExecute()`
  только пишет в лог) — можно безопасно гонять DEV-бот, не плодя пользователей
  на боевом StrongSwan.
  ⚠️ `VpnServiceProvider` регистрирует синглтон **без аргумента** (дефолтный
  сервер). Везде, где нужен конкретный сервер, код делает `new VpnService($name)`
  в обход контейнера.

## Telegram-бот

**`App\Telegram\Handler`** (extends `WebhookHandler`) — все action'ы и команды
в одном классе. Главная точка — `start()`.

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

Продумано под интермиттентные сетевые сбои:

- **DB::transaction** — User и Entry-Bonus коммитятся вместе, никаких сетевых
  вызовов внутри.
- **safeSend** — оборачивает любой Telegram-send в try/catch, проглатывает
  Throwable, логирует warning. **Обязательно** чтобы webhook вернул 200: иначе
  Telegram ретраит вебхук, второй заход видит юзера зарегистрированным, уходит
  в `greetExisting` и бонус теряется.
- **ensureEntryBonus** — идемпотентная страховка по `subject_type='entry_bonus'`.

### Платежи

YooKassa-инвойсы через Telegram. `handlePreCheckoutQuery` подтверждает,
`handleSuccessfulPayment` создаёт `deposit` и при наличии referrer'а начисляет
% бонус (`referral_bonus_percent`, сейчас 20%).

## ⚠️ Observers — скрытый слой автоматики

Регистрируются в `AppServiceProvider::boot()`. **Это самая неочевидная часть
системы** — они дублируют логику крона и делают синхронные HTTP-вызовы к
StrongSwan прямо из жизненного цикла моделей.

**`UserObserver`** — на `created` шлёт `NewUserRegistered` на ADMIN_EMAIL.

**`ClientObserver`** — на `updated`, только если `isDirty('is_active')`:
- `false -> true` → юзеру в Telegram «✅ VPN-канал активирован»
- `true -> false` → «🚫 VPN-канал заблокирован»

То есть **любой клик «Swap Status» в админке немедленно шлёт сообщение
конечному пользователю**. Как и любая активация/деактивация из крона или из
TransactionObserver. Ретраев тут нет, только try/catch.

**`TransactionObserver`** — на `created` и `updated`:
- `created` с `type !== 'deposit'` → выходит сразу (withdraw от крона не трогает)
- `created` (deposit) → письмо `NewDeposit` + `checkBalanceAndManageClients()`
- `updated` при изменении `is_active` или `amount` → `checkBalanceAndManageClients()`

`checkBalanceAndManageClients()` **повторяет логику крона**: баланс ≥ 0 →
активирует всех неактивных клиентов, < 0 → деактивирует всех активных. Реальные
HTTP-запросы к StrongSwan уходят синхронно — при пополнении из бота, при правке
транзакции в Orchid, при отмене транзакции.

**Каскад, который легко забыть:**
```
правка транзакции в админке
  -> TransactionObserver -> VpnService (HTTP к StrongSwan)
    -> меняется Client.is_active
      -> ClientObserver -> сообщение юзеру в Telegram
```

## Ежедневный cron — списание и сводка

- `routes/console.php`: `Schedule::command('vpn:daily-charge')->dailyAt('10:30')`
  **только в `production`-окружении**.
- `App\Console\Commands\ChargeVpnClients` (`vpn:daily-charge`) — единственная
  проектная artisan-команда. Итерируется по consumer'ам с активными клиентами,
  считает price из `config/vpn.php`, создаёт `withdraw` с
  `subject_type='vpn_service'`. Дальше: баланс < 0 → деактивирует всех активных,
  ≥ 0 → реактивирует заблокированных.
- **Уведомления:** per-user предупреждение если `daysLeft < 7` или баланс в
  минусе. В конце прогона — Telegram-сводка админу + письма `ClientsBlocked`
  (если кого-то заблокировали) и `DailySummary` (всегда).
- `--force-notify` — шлёт уведомления независимо от условий.
- `--user-ids=1,2,3` — обработать только конкретных consumer'ов.

⚠️ Списание создаётся **всегда**, даже если баланс уже глубоко отрицательный —
долг накапливается без ограничений.

`daysLeft` считается через `Pricing::daysLeft($balance, $totalCharge)` — по
фактическому суточному расходу юзера, а не по базовой цене. Бот в «💼 Баланс»
использует ту же пару методов, поэтому цифры в боте и в уведомлениях крона
совпадают.

## Notifications и очередь

Пять уведомлений, все канал **только mail**, все реализуют **`ShouldQueue`**:

| Класс | Триггер |
|---|---|
| `NewUserRegistered` | UserObserver::created |
| `NewDeposit` | TransactionObserver::created (deposit) |
| `VpnClientCreated` | VpnService::createClient() |
| `ClientsBlocked` | ChargeVpnClients, если кого-то заблокировали |
| `DailySummary` | ChargeVpnClients, всегда |

⚠️ **`ShouldQueue` + `QUEUE_CONNECTION=database` = письма не уходят** без
постоянно запущенного `php artisan queue:work`. Сейчас спасает
`QUEUE_CONNECTION=sync` — уведомления отправляются синхронно в том же процессе.
Если когда-нибудь переключишь на `database`/`redis` — **обязательно подними
воркер через supervisor**, иначе почта молча осядет в таблице `jobs`.

⚠️ `config/mail.php` по умолчанию `env('MAIL_MAILER', 'log')` — без
`MAIL_MAILER` в `.env` письма молча уходят в лог-файл.

Отдельных blade-шаблонов нет, всё на `MailMessage`.

## Админка (Orchid)

Меню: VPN Clients / Transactions / Consumers / Users / Roles / Instructions.

- **`ClientScreen`** — таблица клиентов, пароли **открытым текстом**. Действия
  `swap` (дёргает VpnService activate/deactivate) и `delete` (сначала removeUser
  на StrongSwan, потом из БД). При создании есть компенсирующий откат: если
  `addUser` упал — Client-запись удаляется.
- **`Consumer/ConsumerListScreen`** — consumer'ы с `withCount` и подзапросом
  баланса.
- **`Consumer/ConsumerEditScreen`** — самый нагруженный экран. Форма юзера
  (автоматически доцепляет роль `consumer`), таблица его клиентов со swap/delete,
  модалка **«Add Transaction»**, ручное управление рефералами
  (`attachReferral`/`detachReferral`), удаление consumer'а (блокируется если
  есть клиенты).
  ⚠️ **Транзакции отсюда создаются с `subject_type = null`** — это первопричина
  «двойного бонуса» (см. Тонкости).
- **`Consumer/ConsumerCreateScreen`** — создаёт роль `consumer`, если её нет в БД.
- **`Transaction/*`** — CRUD транзакций, можно править сумму и `is_active` задним
  числом. ⚠️ `updateTransaction()` отбрасывает null через `array_filter` —
  **обнулить поле через этот экран нельзя**. Роут edit объявлен как
  `transactions/{id}/edit` намеренно без route-model binding.

⚠️ **У проектных экранов нет `->permission()`** — любой пользователь с доступом
в `/admin` управляет клиентами, транзакциями и балансами.

⚠️ **Девять демо-экранов `Screens/Examples/*` зарегистрированы в
`routes/platform.php`** и доступны по прямым URL `/admin/examples/*` в проде —
просто скрыты из меню.

⚠️ `resources/views/vendor/platform/**` — опубликованные blade-шаблоны Orchid
(~100 файлов). Апгрейд `orchid/platform` не подтянет изменения вьюх сам.

## Роуты

- `routes/web.php` — практически пустой: `/` → стоковый welcome,
  `/help` → **редирект на внешний perplexity-поиск**. При этом в проекте лежит
  готовая `resources/views/help/main.blade.php` (полноценная инструкция по
  IKEv2), которая никуда не подключена, а кнопка «Как подключиться» в боте ведёт
  как раз на `/help`. Блок `vpn-test` роутов закомментирован, вместе с ним
  мёртвые `VpnTestController` и `views/vpn/test.blade.php`.
- `routes/platform.php` — экраны админки.
- `api.php` **отсутствует**. Единственный не-web эндпоинт — health-check `/up`
  из `bootstrap/app.php`.
- Webhook-роут `/telegraph/{token}/webhook` регистрирует сам пакет Telegraph.

## Sending Telegram-сообщений: прокси и ретраи

### Прокси (`App\Support\TelegramProxy`)

`config/telegram.php` читает `TELEGRAM_PROXY_*`. Когда
`TELEGRAM_PROXY_ENABLED=true`, `AppServiceProvider::boot()` вызывает
`TelegramProxy::applyGlobal()` → через `Http::globalOptions([...])` прописывает
Guzzle-опцию `proxy.https`. Все исходящие HTTPS Laravel (включая Telegraph
send()) идут через SOCKS5/HTTP прокси.

VPN-сервер (HTTP, не HTTPS) не затрагивается. Дополнительные исключения —
`TELEGRAM_PROXY_NO_HOSTS` (comma-separated).

### Ретраи (`App\Support\TelegraphRetry`)

`attempt(callable, attempts, delayMs, context)`. По умолчанию 5 попыток, 500ms:

- **proxy disabled** → все 5 попыток direct
- **proxy enabled, fallback off** → все 5 через прокси
- **proxy enabled, fallback on** → попытки 1-3 через прокси, 4-5 direct

Используется только в `ChargeVpnClients`. В `Handler` ретраев нет — там
`safeSend` без retry, потому что webhook нельзя долго удерживать.

## ENV переменные

Боевой `.env` в `.gitignore`. ⚠️ `.env.example` **неполный** — в нём только
стоковый Laravel + `TELEGRAM_PROXY_*`, проектных переменных нет.

```
APP_ENV=production         # иначе schedule в console.php не зарегистрируется
DB_*                       # удалённая MySQL
QUEUE_CONNECTION=sync      # см. раздел про Notifications
LOG_CHANNEL=stack
LOG_STACK=daily            # ротация 14 дней (config/logging.php)
LOG_LEVEL=error            # поднять до warning/debug для отладки

TELEGRAPH_BOT_TOKEN=       # боевой бот (от @BotFather)
TELEGRAPH_BOT_USERNAME=
TELEGRAPH_BOT_NOTIFY_ID=   # id записи telegraph_bots в БД, для уведомлений
TELEGRAPH_PAYMENT_PROVIDER_TOKEN=   # YooKassa
ADMIN_CHAT_ID=             # telegram_id админа
ADMIN_EMAIL=               # для DailySummary / ClientsBlocked / NewDeposit

VPN_SECRET_KEY=            # auth к StrongSwan-серверу

TELEGRAM_PROXY_ENABLED=true
TELEGRAM_PROXY_URL=socks5h://user:pass@host:port
TELEGRAM_PROXY_FALLBACK_DIRECT=true
TELEGRAM_PROXY_NO_HOSTS=
```

## Деплой

```bash
cd /path/to/public_html
git pull
php artisan config:clear      # если менялись .env / config
php artisan migrate           # если менялись миграции
# composer install — только если менялись зависимости
```

🚨 **Никогда не делать `php artisan config:cache` на этом проекте.** В коде 13
мест читают `env()` напрямую в обход `config()` — `ADMIN_EMAIL`,
`TELEGRAPH_BOT_NOTIFY_ID`, `ADMIN_CHAT_ID`, `TELEGRAPH_BOT_TOKEN`,
`TELEGRAPH_BOT_USERNAME` (в ChargeVpnClients, Handler, VpnService и всех трёх
обсерверах). После кеширования конфига `env()` возвращает **null**, и все
уведомления молча перестают работать без единой ошибки в логах.

Артефактов в репо нет (vendor/, node_modules/, .env — в gitignore).

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
}
```

Форвардится только `/telegraph/*`, остальное 404 — Caddy не светит наружу всё
что есть на проде.

`email` в глобал-блоке — **обязательно**. Без него Caddy при каждом restart
регистрирует новый ACME-аккаунт и быстро упирается в Let's Encrypt rate limit
(10 регистраций / 3 часа / IP).

⚠️ **Не добавляй блок `log { output file ... }` при первой установке.** На свежей
Ubuntu 24.04 systemd-unit Caddy использует директиву `LogsDirectory`, и
созданная руками `/var/log/caddy` конфликтует с ней — Caddy падает на старте с
`permission denied`. Operational-логи и так доступны через `journalctl -u caddy`.

### Установка Caddy с нуля

```bash
apt update
apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
apt update && apt install -y caddy
# записать Caddyfile, затем:
caddy validate --config /etc/caddy/Caddyfile
systemctl restart caddy
journalctl -u caddy -n 50 --no-pager | grep -iE 'obtained|error|certificate'
# ждём: certificate obtained successfully
```

Порты 80/443 TCP свободны — Amnezia использует UDP-каналы и один TCP-порт под
SOCKS5, конфликта нет. Проверить: `ss -tlnp | grep -E ':80\b|:443\b'`.

### Webhook у Telegram

URL = `https://tg.<domain>/telegraph/<BOT_TOKEN>/webhook`. Ставить через прокси
(direct с прода мёртв):

```bash
BOT_TOKEN=$(grep ^TELEGRAPH_BOT_TOKEN .env | cut -d= -f2 | tr -d '"')
PROXY=$(grep ^TELEGRAM_PROXY_URL .env | cut -d= -f2-)
curl -x "$PROXY" -m 20 -s \
  "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://tg.<domain>/telegraph/${BOT_TOKEN}/webhook"
curl -x "$PROXY" -m 20 -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
```

⚠️ **При смене IP внешней VPS обязательно передавай `&ip_address=<NEW_IP>`** в
`setWebhook`. Telegram кеширует резолв домена у себя и может ещё долго
таймаутиться в старый адрес, даже когда мировой DNS уже отдаёт новый. Признак
проблемы — в `getWebhookInfo` поле `ip_address` показывает старый IP и растёт
`pending_update_count`.

### Замена SOCKS5

```bash
cd /path/to/public_html
sed -i 's|^TELEGRAM_PROXY_URL=.*|TELEGRAM_PROXY_URL=socks5h://USER:PASS@HOST:PORT|' .env
php artisan config:clear
PROXY=$(grep ^TELEGRAM_PROXY_URL .env | cut -d= -f2-)
for i in 1 2 3 4 5; do
  curl -x "$PROXY" -m 15 -o /dev/null -s -w "$i: http=%{http_code} time=%{time_total}s\n" https://api.telegram.org/
done
# ждём 5/5 http=302
```

## Тонкости которые легко забыть

- **`schedule:run` только в production.** На DEV cron не зарегистрирован.
  Для отладки руками: `php artisan vpn:daily-charge --force-notify`.
- **`subject_type='entry_bonus'`** — единственный надёжный маркер вступительного
  бонуса. Сравнение по `comment` ненадёжно (комменты на русском, с опечатками).
- **Скрытый «двойной бонус»:** consumer'ы, которым админ начислил Entry Bonus
  вручную через `ConsumerEditScreen` (там `subject_type=null`), при следующем
  `/start` получат **ещё один** бонус через `ensureEntryBonus`. Лечится правкой
  `subject_type` в БД на `entry_bonus` для таких записей.
- **DNS у хостера подменяет:** локальный resolver может возвращать «свой» IP для
  свежего поддомена. Проверять реальный резолв — `dig @8.8.8.8 <domain> +short`.
  Для curl-тестов с прода — `--resolve <domain>:443:<real_ip>`.
- **.su и другие «редкие» TLD:** ZeroSSL на free-плане не выдаёт cert
  (`rejectedIdentifier: DNS identifier is disallowed`). Let's Encrypt — выдаёт.
  Buypass в 2026 отдаёт 404 на ACME-endpoint.
- **Let's Encrypt rate limit «10 регистраций / 3 часа / IP»:** очень легко
  упереться при отладке Caddy, если чистить ACME-state. Решение — **не чистить**
  `/var/lib/caddy/.local/share/caddy/acme` и обязательно `email` в Caddyfile.
- **Amnezia на клиенте режет github:** при включённом VPN `git push` по SSH не
  проходит ни на 22, ни на `ssh.github.com:443`. Выключить VPN на момент push.
- **Логи:** `LOG_STACK=daily`, файлы `storage/logs/laravel-YYYY-MM-DD.log`,
  14 дней (`LOG_DAILY_DAYS`).
- **Логи Apache** (Hestia): `~/web/<domain>/logs/<domain>.log` — симлинки на
  `/var/log/apache2/domains/`.
- **Пароль VPN-клиента = telegram_id юзера.** Это by design, не баг —
  `creatOneClientFromTelegram` передаёт его вторым аргументом. Если у юзера нет
  `telegram_username`, логин генерируется как `c_{user_id}_{rand(111,999)}`.

## Известные проблемы / техдолг

Ничего из этого не горит, но знать полезно. Примерно в порядке серьёзности:

1. **`clients.is_active` не в миграциях** — окружение с нуля не поднимется.
2. **`env()` вместо `config()` в 13 местах** — `config:cache` молча ломает все
   уведомления. См. раздел Деплой.
3. **Боевой IP StrongSwan в git** — `config/vpn.php` содержит host и порт
   открытым текстом, репозиторий публичный. (`secret_key` уже переведён на
   `env()` без дефолта; `update_webhook.php` с токеном DEV-бота — в
   `.gitignore`, там ок.)
4. **Экраны админки без `->permission()`** — нет разграничения доступа.
5. **Демо-экраны Orchid доступны в проде** по `/admin/examples/*`.
6. **Обсерверы дублируют логику крона** и делают синхронные HTTP-вызовы к
   StrongSwan из жизненного цикла моделей.
7. **Нет индексов** под агрегаты баланса.
8. **Долг накапливается неограниченно** — списание идёт даже при глубоко
   отрицательном балансе.
9. **Нет тестов** — только стоковые ExampleTest.
10. **Мёртвый код:** `VpnTestController` + `views/vpn/test.blade.php` (роуты
    закомментированы), `views/help/main.blade.php` (готовая инструкция, никуда
    не подключена, вместо неё `/help` редиректит на perplexity).
11. **`telegram_username` unique** — юзер может сменить username в Telegram на
    уже занятый другой записью, будет конфликт при обновлении.
12. **Расхождение валюты в текстах:** приветствие говорит «руб/сутки», баланс и
    уведомления — «у.е.», админка показывает «₽». Фактически 1 ₽ = 1 у.е.

## Откаты / kill switch

- **Отключить прокси:**
  `sed -i 's/^TELEGRAM_PROXY_ENABLED=.*/TELEGRAM_PROXY_ENABLED=false/' .env && php artisan config:clear`
- **Вернуть webhook на прямой URL:**
  `curl -x "$PROXY" "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://<prod-domain>/telegraph/${BOT_TOKEN}/webhook"`
- **Удалить webhook совсем:** `?url=` (пустой).
- **Снести Caddy:** `systemctl stop caddy && apt remove -y caddy` — Amnezia на
  той же VPS не заметит.

## История ключевых фиксов

- Try/catch в `vpn:daily-charge` чтобы одна сломанная отправка не валила весь
  прогон с exit code 1.
- Daily-ротация логов вместо одного бесконечно растущего файла (был 66 MB).
- Атомарная регистрация User + Entry Bonus в `DB::transaction`, `safeSend` в
  `Handler::start`, идемпотентный `ensureEntryBonus`.
- `TelegraphRetry` с proxy-first / direct-fallback стратегией.
- `TelegramProxy` + `TELEGRAM_PROXY_*` для роутинга исходящих через SOCKS5.
- Caddy на внешней VPS как reverse-proxy для входящих webhook'ов.
- Переезд внешней VPS с переносом обеих ролей (Caddy + SOCKS5).

## Полезные команды

```bash
# Зарегистрированные scheduled-команды
php artisan schedule:list

# Прогон daily-charge руками
php artisan vpn:daily-charge --force-notify
php artisan vpn:daily-charge --user-ids=42,43

# Боты и чаты Telegraph в БД
php artisan tinker --execute="\DefStudio\Telegraph\Models\TelegraphBot::all()->each(fn(\$b) => print \$b->id.' '.\$b->name.PHP_EOL);"

# Кэши
php artisan config:clear      # после правки .env
php artisan optimize:clear    # полная очистка
# НО НЕ config:cache — см. раздел Деплой

# Логи
ls -la storage/logs/
grep -hE 'NOTIKI|AdminSummary|DailySummary|TelegraphRetry' storage/logs/laravel-$(date +%Y-%m-%d).log | tail -30

# Что Telegram думает про webhook
curl -x "$PROXY" "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"

# Caddy на внешней VPS
systemctl status caddy --no-pager
journalctl -u caddy -n 50 --no-pager | grep -iE 'obtained|error|certificate'
```
