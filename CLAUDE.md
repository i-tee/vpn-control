# Gatekeeper — техническая справка

Биллинг и админка VPN-сервиса. Юзеры общаются с Telegram-ботом, бот регистрирует
их, начисляет вступительный бонус, продаёт доступ, списывает посуточно, создаёт
логин/пароль на удалённом StrongSwan IKEv2-сервере. Админка живёт на той же
платформе (Orchid Platform).

## Стек

- **Laravel 12** на **PHP 8.3**
- **Orchid Platform 14** — админка (`/admin`)
- **defstudio/telegraph 1.60** — обёртка над Telegram Bot API
- **SQLite** (WAL) — локальный файл на сервере, `storage/database/database.sqlite`
- **FrankenPHP** (PHP + встроенный Caddy с авто-HTTPS) в Docker — прод-образ `infra/app/`
- **StrongSwan IKEv2** на том же сервере, API на unix-сокете — `infra/strongswan/`

## Локальная разработка

Тот же образ, что в проде (`infra/app/Dockerfile`, стадия `dev`), только код
подключён папкой: правки видны сразу, пересобирать ничего не нужно. Корневой
`docker-compose.yml` — только для разработки.

```bash
docker compose up -d --build                              # первый раз или после правок Dockerfile
touch database/database.sqlite
docker compose exec app php artisan migrate:fresh --seed  # чистая база с демо-данными
```

| Что | Адрес |
|---|---|
| админка | http://localhost:18080/admin — `admin@gatekeeper.local` / `password` |
| админка глазами менеджера | `manager@gatekeeper.local` / `password` |
| mailpit (все письма приложения) | http://localhost:18125 |
| SMTP mailpit с хоста | localhost:11125 (из контейнера `mailpit:1025`) |

**Порты** — отдельный диапазон 1xxxx: у других локальных проектов заняты 80,
1025-1027, 3000-3100, 3306, 4000, 5173, 5432-5442, 8025-8094. Переопределяются
через `HTTP_PORT`, `MAILPIT_UI_PORT`, `MAILPIT_SMTP_PORT` в `.env`. После смены
портов — `docker compose up -d --force-recreate`.

**Демо-данные** (`database/seeders/DevSeeder.php`): 8 вымышленных покупателей
на все случаи — с деньгами, на 3 дня, заблокированный должник, пара «пригласил /
приглашён» с реферальными бонусами, два канала, без канала, сгенерированный
логин. telegram_id из диапазона 9000000xx. Сидер запускается только при
`APP_ENV=local`.

**Что важно:**

- **VPN-вызовы в деве заглушены** (`APP_ENV=local`) — каналы включаются и
  блокируются только в базе, на настоящий сервер ничего не уходит.
- **artisan — внутри контейнера:** `docker compose exec app php artisan ...`.
  Если запустить `route:cache`/`config:cache` прямо в WSL, кеш запомнит пути
  `/home/itee/...`, а в контейнере код лежит в `/app` — всё сломается.
  Лечится `docker compose exec app php artisan optimize:clear`.
  `php artisan test` можно гонять и в WSL — он ничего не кеширует.
- **`DB_JOURNAL_MODE=delete` в локальном `.env`.** WAL держит общую память
  в `-shm` файле, а через проброс папки Docker Desktop это ненадёжно: база
  ломается с `database disk image is malformed`. На проде база на родном
  диске, там WAL.
- **Контейнер работает от твоего uid** (1000), поэтому файлы, которые он
  создаёт, твои. Если что-то в `storage` вдруг стало чужим —
  `docker compose exec -u 0 app chown -R 1000:1000 /app/storage /app/bootstrap/cache /app/database`.

**DEV-бот** (`vipvpn_dev_bot`, не боевой!). Токен — `TELEGRAPH_BOT_TOKEN` в
локальном `.env`, сидер регистрирует его как бота №1. Чтобы бот отвечал:

```bash
ngrok http 18080                                   # в Windows, даёт https-адрес
# APP_URL=<адрес ngrok> в .env, затем:
docker compose exec app php artisan telegraph:set-webhook 1
```

Адрес ngrok меняется при каждом запуске — тогда снова правишь `APP_URL` и
ставишь вебхук. Боевой бот `viptrafic_bot` к локалке не подключать: вебхук у
Telegram один, прод перестанет получать сообщения.

## Топология сети

С 17.11.2026 всё живёт на **одном сервере вне РФ** (Timeweb, Амстердам):

```
Telegram ──HTTPS──> gatekeeper.<domain> :443 ─┐
админка  ──HTTPS──> gatekeeper.<domain> :443 ─┤  контейнер app (FrankenPHP, network_mode: host)
                                              │    Laravel ── SQLite (./storage/database)
                       контейнер scheduler ───┤    schedule:work: списание 10:30, сверка 10:45 UTC
                                              │
                                              └─ unix-сокет /run/strongswan-api/api.sock
                                                       │
VPN-клиенты ──IKEv2 UDP 500/4500──> x.<domain> ── charon (StrongSwan) <── strongswan_api.py
```

- Сервер за границей, поэтому Telegram ходит к боту напрямую, а бот к
  `api.telegram.org` без прокси. `TELEGRAM_PROXY_ENABLED=false`.
- Учёт и VPN падают и поднимаются вместе: внешних зависимостей нет.
- VPN API наружу не торчит вообще, порта 5000 больше нет.
- Сертификат админки получает Caddy внутри контейнера. Сертификат VPN
  (`x.<domain>`) продлевает certbot через webroot `/var/www/acme`, который
  отдаёт тот же Caddy.
- DNS зоны остаётся у Beget. На сервере к резолверу хостера добавлены
  публичные (`/etc/systemd/resolved.conf.d/gatekeeper.conf`).

Как это поднять и обслуживать — `infra/app/README.md` и
`infra/strongswan/README.md`.

**Старая схема (до 17.11.2026):** прод на Hestia в РФ, база MySQL на Beget,
вебхук через Caddy-ретранслятор `tg.<domain>` и исходящие через SOCKS5 на
внешней VPS. Разделы «Внешняя VPS» и «Прокси» ниже описывают её и нужны только
если прод снова окажется в РФ.

## Ключевые модели

- **`App\Models\User`** (extends Orchid User) — telegram_id, telegram_username,
  имя, referrer_id. Роль `consumer` (покупатели VPN) или платформенные (админы).
  Содержит слой статических telegram-фасадов: `getIdByTelegramId`,
  `getClientsByTelegramId`, `getBalanceByTelegramId`, `creatOneClientFromTelegram`.
  Роль покупателя: `User::ROLE_CONSUMER`, скоуп `User::consumers()`,
  `User::consumerRole()` (создаёт роль, если её нет).
- **Баланс считается в одном месте:** `Transaction::balanceFor($userId)` и
  константа `Transaction::BALANCE_SQL`. Через них работают `User::balance()`,
  аксессор `$user->balance`, скоуп `withBalance()`, `BinderService` и крон.
  Формула написана с одинарными кавычками, чтобы одинаково работать в SQLite и MySQL.
- **`App\Models\Client`** — VPN-канал на сервере: name (логин), password
  (открытым текстом), server_name, is_active, user_id.
- **`App\Models\Transaction`** — deposit / withdraw, amount, subject_type
  (`entry_bonus`, `vpn_service`, `yookassa`, `referral_bonus`,
  `referral_activation` или **null** при
  ручном создании через админку), comment, is_active.
  Баланс = `SUM(deposit) - SUM(withdraw)` по активным транзакциям.

### Схема БД

```
users         id, name, email, password, permissions(jsonb), telegram_verified_at,
              telegram_id(unique), telegram_first_name, telegram_last_name,
              telegram_username(unique), referrer_id(FK users, nullOnDelete)
clients       id, name(unique), password, user_id(FK cascade), server_name,
              owner_id, telegram_nickname, is_active, timestamps
              index(user_id, is_active)
transactions  id, user_id(FK cascade), type ENUM(deposit|withdraw),
              amount DECIMAL(10,2), subject_type, subject_id,
              external_id(unique, nullable), comment TEXT,
              is_active BOOL default true, timestamps
              index(user_id, is_active), index(subject_type, subject_id)
roles         запись slug=consumer создаётся миграцией
```

`php artisan migrate` на пустой базе даёт рабочее приложение: колонка
`clients.is_active` и роль `consumer` теперь создаются миграциями
(`2026_11_17_*`). Миграции проверены на SQLite.

`clients.name` уникален, потому что это логин на VPN-сервере.
`transactions.external_id` хранит внешний ID платежа (`yookassa:<charge_id>`),
unique не даёт зачислить один платёж дважды.

⚠️ `clients.price` нет — цена всегда из `config/vpn.php` по `server_name`.

## Тарификация — единый центр

Вся цена определяется **только** в `config/vpn.php`:

```php
'default_price' => 20,  // цена за клиент в сутки — единственный источник правды
'entry_bonus'   => 100, // вступительный бонус (при цене 20 = 5 дней теста)
'referral_invitee_bonus'    => 200, // бонус приглашённому по ссылке вместо entry_bonus (при цене 20 = 10 дней)
'referral_activation_bonus' => 100, // бонус приглашающему, когда приглашённый прошёл первое ежедневное списание
'referral_bonus_percent'    => 20,  // % от каждого пополнения приглашённого
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
| `Pricing::referralInviteeBonus()` | бонус приглашённому по реферальной ссылке (вместо `entryBonus`) |
| `Pricing::referralActivationBonus()` | бонус приглашающему за активацию друга (первое списание) |
| `Pricing::referralPercent()` | % от пополнений приглашённого, который получает приглашающий |
| `Pricing::referralFreeDays()` | сколько дней теста даёт реферальный бонус (для `{invitee_days}` в тексте) |
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
  `server_name` в конструкторе, адрес и ключ из `config/vpn.php`
  (`VPN_API_SOCKET` или `VPN_API_HOST`/`VPN_API_PORT`, плюс `VPN_SECRET_KEY`).
  Если задан `VPN_API_SOCKET`, запросы идут через unix-сокет
  (`CURLOPT_UNIX_SOCKET_PATH`) — так Laravel в контейнере говорит с API на хосте.
  Методы: `getUsers()`, `getSessions()`, `addUser()`, `removeUser()` и обёртки
  над каналами в БД. Сам API описан в `infra/strongswan/README.md`.
  **В не-production окружении сетевые вызовы заглушены** (`shouldExecute()`
  только пишет в лог) — можно безопасно гонять DEV-бот, не плодя пользователей
  на боевом StrongSwan.
  Логин и пароль проверяются регуляркой (`USERNAME_PATTERN`, `PASSWORD_PATTERN`):
  они пишутся в `ipsec.secrets`, и кавычка или перевод строки позволили бы
  дописать туда чужую строку. Ключ уходит и в заголовке `X-Api-Key`, и в теле
  запроса. Ключ и пароль в лог не пишутся. Если сервер не принял нового юзера,
  `createClient()` удаляет запись из БД.
  `VpnServiceProvider` регистрирует обычный bind (не синглтон):
  `app()->makeWith(VpnService::class, ['serverName' => $name])`. Через контейнер
  его берёт `vpn:reconcile`, поэтому в тестах сервер подменяется. `AccessService`
  и экраны админки пока делают `new VpnService($name)` напрямую.
- **`App\Services\AccessService`** — единственное место, где решается, включён
  ли VPN: `sync($user)` при балансе ≥ 0 активирует все каналы юзера, при < 0
  блокирует. Вызывают крон и `TransactionObserver`. Канал без имени пропускается
  с предупреждением, ошибка по одному каналу не мешает остальным.
- **`App\Services\PaymentService`** — `credit($user, $totalAmountMinor, $chargeId)`
  зачисляет оплату ЮKassa ровно один раз (по `external_id`) и начисляет
  реферальный процент. Сумма берётся из `total_amount` платежа, а не из payload.

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

### Подтверждение telegram_id и привязка импортированных

`users.telegram_verified_at` — когда бот сам видел этого юзера (регистрация
или /start). У юзеров из `vpn:import-secrets` пусто: их telegram_id угадан по
паролю канала и мог оказаться неверным (каналы, заведённые руками, с паролем
вроде номера телефона).

- **Автоматически:** если /start пришёл с id, которого нет в базе, бот ищет
  **неподтверждённого** юзера с таким же `telegram_username` и привязывает к
  нему настоящий id (`Handler::relinkImportedUser` → `User::linkTelegramId`).
  Канал и баланс остаются, второго аккаунта и второго бонуса нет. Подтверждённый
  аккаунт так не перехватить, даже если его username занял другой человек.
- **Вручную:** в карточке покупателя в админке поля Telegram ID и username.
  Нужно, когда логин канала не совпадает с username в Telegram — бот такого
  не узнает. Настоящий id человек узнаёт у @userinfobot.
- `User::linkTelegramId()` удаляет чат Telegraph со старым id и создаёт с
  новым — без чата крон не сможет писать юзеру.

### Платежи

YooKassa-инвойсы через Telegram. `handlePreCheckoutQuery` проверяет, что юзер
из payload существует и валюта RUB, и отвечает через
`$this->bot->answerPreCheckoutQuery()`. `handleSuccessfulPayment` отдаёт
зачисление в `PaymentService`: повторный вебхук с тем же
`provider_payment_charge_id` ничего не зачисляет. Реферальный процент
(`referral_bonus_percent`, сейчас 20%) пишется с `subject_id` = id приглашённого.

**Лог платежей** — отдельный канал `payments` (`config/logging.php`): всё про
инвойсы и оплаты (`[YKASSA]`, `[Payment]`) пишется в
`storage/logs/payments-YYYY-MM-DD.log` на уровне info и хранится 90 дней,
независимо от `LOG_LEVEL`. Ошибки дублируются в общий лог. Жалобу «не могу
оплатить» проверять здесь:
`grep -h YKASSA /opt/gatekeeper/storage/logs/payments-*.log | tail`.

## Реферальная программа

Ссылка `https://t.me/<bot>?start=ref_<id>`, где `<id>` — `users.id` приглашающего. Работает в три шага:

1. **Приглашённый** получает `referral_invitee_bonus` вместо `entry_bonus` при регистрации (`start()` → `createEntryBonus($user, byInvite: true)`). `subject_type` остаётся `entry_bonus`, поэтому идемпотентность `ensureEntryBonus` работает как прежде; она тоже учитывает `referrer_id`.
2. **Приглашающий** получает `referral_activation_bonus`, когда приглашённый **прошёл первое ежедневное списание**. Выплату делает крон `vpn:daily-charge`, метод `grantReferralActivationBonus`. Транзакция: `subject_type='referral_activation'`, `subject_id = id друга`. Одна выплата на пару навсегда.
3. **Приглашающий** получает `referral_bonus_percent`% с каждого пополнения друга: `subject_type='referral_bonus'`, начисляет `handleSuccessfulPayment`.

Почему бонус приглашающему привязан к списанию, а не к регистрации: защита от самонакрутки через несколько Telegram-аккаунтов. Регистрация ничего не стоит и её можно наштамповать с кучи аккаунтов; первое списание означает, что друг реально потратил деньги на VPN.

### Где бот напоминает о программе

| Место | Что показывается | Код |
|---|---|---|
| Главное меню → «🤝 Реферальная программа» | экран `text.referral.program`: ссылка, приглашено, «Заработано на друзьях», кнопка «Поделиться» | `Handler::ref()` |
| «💼 Баланс» | строка «🤝 Заработано на друзьях» и кнопка «Пригласить друга» | `Handler::showbalance()` |
| После успешной оплаты | абзац `text.referral.after_payment` и кнопка «Поделиться» | `Handler::handleSuccessfulPayment()` |
| Крон: «осталось N дней» и «заблокирован» | абзац `text.referral.nudge` (у предупреждения и блокировки) и кнопка «Пригласить друга» | `ChargeVpnClients::sendBalanceNotification()` |
| Приглашающему: друг зарегистрировался | сообщение и кнопка «Поделиться» | `Handler::notifyReferrerAboutNewUser()` |
| Приглашающему: друг пополнил баланс | сообщение о проценте с пополнения | `Handler::notifyReferrerAboutBonus()` |
| Приглашающему: друг прошёл первое списание | сообщение о бонусе и кнопка «Пригласить друга» | `ChargeVpnClients::grantReferralActivationBonus()` |

Кнопки «Поделиться» и «Пригласить друга» — url-кнопки на `https://t.me/share/url?url=...&text=...`. Telegram открывает выбор чата с готовым текстом `text.referral.share` (плейсхолдеры `{days}` и `{link}`), без копирования ссылки. Хелперы `referralLink`, `shareUrl`, `giftButton` продублированы в `Handler` и `ChargeVpnClients` (у команды нет доступа к Handler; в `Handler` есть ещё `shareButton`). Меняешь формат ссылки или текст `share` — правь в обоих местах.

Тексты все в `config/bot.php`: `text.referral.{share,nudge,after_payment,program}` и `button.{share,gift}`. Плейсхолдеры в фигурных скобках (`{days}`, `{bonus}`, `{percent}`, `{link}`, `{invitee_days}`, `{free_days}`, `{invited}`, `{earned}`) подставляются в коде через `str_replace`; в конфиге остаются как есть.

### Сводка админу

В Telegram-сводке крона (`sendAdminSummary`) три новые строки:
- `🆕 Новых за сутки` — consumer'ы, созданные за последние 24 часа;
- `🔗 из них по ссылке` — у которых задан `referrer_id`;
- `🎁 Реферальных бонусов сегодня` — выплаты `referral_activation` в этом прогоне.

### ⚠️ Подводные камни

- **Username бота** берётся из `config('gatekeeper.bot_username')` (`TELEGRAPH_BOT_USERNAME`). Без него ссылка превращается в `https://t.me/?start=ref_N`.
- **«Заработано на друзьях»** = сумма активных (`is_active=true`) транзакций пользователя с `subject_type IN ('referral_bonus','referral_activation')`. Отменённые через админку выплаты в сумму не входят.
- **Идемпотентность выплаты за активацию не смотрит на `is_active`**: если админ отменил транзакцию `referral_activation`, повторно её не выдаст, запись с этим `subject_id` уже есть.
- **Выплата не зависит от знака баланса друга**: `grantReferralActivationBonus` вызывается сразу после списания, до проверки `balanceAfter < 0`.
- Транзакция `referral_activation` создаётся **внутри цикла крона** как `deposit` и триггерит `TransactionObserver`: письмо `NewDeposit` админу и `AccessService::sync()` для приглашающего. Если после этого баланс приглашающего стал ≥ 0, его заблокированные каналы **активируются**. Это ожидаемо, но в логах выглядит как внезапная активация.

## ⚠️ Observers — скрытый слой автоматики

Регистрируются в `AppServiceProvider::boot()`. **Это самая неочевидная часть
системы** — они шлют письма и сообщения и делают синхронные запросы к VPN API
прямо из жизненного цикла моделей. Само решение «включить или заблокировать»
общее с кроном — `AccessService`.

**`UserObserver`** — на `created` шлёт `NewUserRegistered` на ADMIN_EMAIL.

**`ClientObserver`** — на `updated`, только если `isDirty('is_active')`:
- `false -> true` → юзеру в Telegram «✅ VPN-канал активирован»
- `true -> false` → «🚫 VPN-канал заблокирован»

То есть **любой клик «Swap Status» в админке немедленно шлёт сообщение
конечному пользователю**. Как и любая активация/деактивация из крона или из
TransactionObserver. Ретраев тут нет, только try/catch.

**`TransactionObserver`** — на `created` и `updated`, **только после коммита**
(`ShouldHandleEventsAfterCommit`): внутри `DB::transaction` запросы к VPN не
держат базу и не уходят при откате.
- `created` с `type !== 'deposit'` → выходит сразу (withdraw от крона не трогает)
- `created` (deposit) → письмо `NewDeposit` + `AccessService::sync()`
- `updated` при изменении `is_active`, `amount` или `type` → `AccessService::sync()`

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

- `routes/console.php`, **только в `production`-окружении**:
  `vpn:daily-charge` в 10:30 UTC и `vpn:reconcile --fix` в 10:45 UTC.
- `App\Console\Commands\ChargeVpnClients` (`vpn:daily-charge`). Итерируется по consumer'ам с активными клиентами,
  считает price из `config/vpn.php`, создаёт `withdraw` с
  `subject_type='vpn_service'`. Дальше вызывает `AccessService::sync()`:
  баланс < 0 → блокирует, ≥ 0 → включает.
- **Повторный запуск в тот же день не списывает второй раз.** Если у юзера
  сегодня (по UTC) уже есть активное списание `vpn_service`, он пропускается.
  `--force` списывает принудительно.
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

### Сверка с VPN-сервером — `vpn:reconcile`

Сравнивает логины на сервере с каналами в базе:

- **missing** — канал активен в базе, но на сервере его нет: юзер платит и не может войти;
- **leaked** — канал заблокирован в базе, но остался на сервере: VPN бесплатно;
- **unknown** — логин на сервере, которого в базе нет вовсе.

С `--fix` missing добавляются, leaked удаляются. Unknown только попадают в
отчёт: это могут быть юзеры, заведённые руками. При любом расхождении админу
уходит сообщение в Telegram (`App\Support\AdminNotifier`).

### Импорт после потери базы — `vpn:import-secrets`

`php artisan vpn:import-secrets <копия ipsec.secrets> [--balance=N] [--dry-run]`.
Из каждой EAP-строки создаёт юзера с ролью consumer, его канал и чат Telegraph
для уведомлений. Пароль из одних цифр считается telegram_id. Сгенерированный
логин `c_<id>_<rand>` не становится telegram username. Стартовый баланс пишется
как `entry_bonus`, чтобы `/start` не начислил вступительный бонус ещё раз.
Обсерверы на время импорта выключены. Повторный запуск пропускает уже
существующие каналы.

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

**Права:** проектные экраны (клиенты, покупатели, транзакции) требуют право
`platform.vpn`. Демо-экраны Orchid удалены.

- **Владелец** — все права (`php artisan orchid:admin`).
- **Роль «Менеджер»** (`manager`, создаётся миграцией) — `platform.index` +
  `platform.vpn`: клиенты, покупатели, транзакции. Пользователей админки и
  роли не видит (403). Новый менеджер: Пользователи → Создать → роль «Менеджер».

**Колонка Telegram в списке покупателей:** «✓ бот» — бот видел юзера сам;
«старая БД» — восстановлен импортом и ещё не нажимал /start, telegram_id может
быть неверным. Если у такого человека при /start появился новый аккаунт,
старый можно удалить: сначала удалить его канал (это уберёт доступ на
VPN-сервере), потом сам аккаунт. Или не трогать — канал заблокируется сам,
когда кончится баланс.

⚠️ `resources/views/vendor/platform/**` — опубликованные blade-шаблоны Orchid
(~100 файлов). Апгрейд `orchid/platform` не подтянет изменения вьюх сам.

## Роуты

- `routes/web.php` — `/` стоковый welcome, `/help` отдаёт
  `resources/views/help/main.blade.php` (инструкция по IKEv2). На неё ведёт
  кнопка «Как подключиться» в боте.
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

Используется в `ChargeVpnClients` и `AdminNotifier` (сообщения админу, в том
числе отчёт `vpn:reconcile`). В `Handler` ретраев нет — там
`safeSend` без retry, потому что webhook нельзя долго удерживать.

## ENV переменные

Боевой `.env` в `.gitignore`. Полный список с комментариями — в `.env.example`.
Проектные переменные читаются только в `config/gatekeeper.php`, `config/vpn.php`,
`config/telegram.php` и `config/telegraph.php`; в коде везде `config()`.
Токен бота живёт в таблице `telegraph_bots`. `TELEGRAPH_BOT_TOKEN` читается
только локально: `DevSeeder` регистрирует по нему DEV-бота
(`config('gatekeeper.dev_bot_token')`); на проде его нет.

Продовый `.env` лежит в `/opt/gatekeeper/.env` (600, владелец deploy), шаблон —
`infra/app/env.production.example`. `APP_ENV`, `DB_*`, `LOG_*` и путь к сокету
VPN заданы в образе и compose, в `.env` их нет. Главное:

```
APP_KEY, APP_URL, APP_DOMAIN, VPN_DOMAIN, ACME_EMAIL
CACHE_STORE=file           # кеш в файлах, меньше писателей в SQLite
SESSION_DRIVER=database
QUEUE_CONNECTION=sync      # см. раздел про Notifications
MAIL_*                     # smtp.beget.com:465, smtps
ADMIN_EMAIL, ADMIN_CHAT_ID
TELEGRAPH_BOT_NOTIFY_ID=1, TELEGRAPH_BOT_USERNAME
TELEGRAPH_WEBHOOK_SECRET   # Telegram шлёт его в заголовке, чужие вебхуки отбрасываются
TELEGRAPH_PAYMENT_PROVIDER_TOKEN
VPN_SECRET_KEY             # = API_KEY из /etc/strongswan-api.env
TELEGRAM_PROXY_ENABLED=false
```

SQLite настроена в `config/database.php`: `busy_timeout` 10 с, `journal_mode`
WAL, `synchronous` normal. Транзакции, которые сначала читают, а потом пишут
(регистрация, оплата, импорт), идут с 3 попытками: SQLite отвечает
`database is locked` без ожидания, если между чтением и записью в базу успел
написать другой процесс. Laravel распознаёт эту ошибку и повторяет транзакцию.

## Деплой

**Новый прод (после переезда):** push в `main` → GitHub Actions
(`.github/workflows/deploy.yml`) гоняет тесты, собирает образ
(`infra/app/Dockerfile`, FrankenPHP) и по SSH отдаёт его серверу. На сервере
`deploy.sh` делает копию SQLite, миграции, перезапуск и проверку `/up`; при
провале сам откатывается на предыдущую версию. Ключ деплоя может запускать
только `deploy.sh`. Деплой включается переменной репозитория
`DEPLOY_ENABLED=true`, пока её нет — CI только тестирует. Подробно, с первым
запуском и восстановлением базы — `infra/app/README.md`.

```bash
ssh deploy@<host> status      # что запущено
ssh deploy@<host> rollback    # вернуть предыдущую версию
```

Кеши `config/route/view/event` собираются при старте контейнера
(`infra/app/entrypoint.sh`), чистить руками не нужно.

`php artisan config:cache` теперь безопасен: прямых вызовов `env()` вне
`config/` в коде нет, проектные переменные собраны в `config/gatekeeper.php`.
Если добавляешь новую переменную окружения — читай её только в файле из
`config/`, иначе после кеширования она молча станет null.

Артефактов в репо нет (vendor/, node_modules/, .env — в gitignore).

## Наследие: внешняя VPS (Caddy + SOCKS5)

⚠️ **До 17.11.2026.** Нужна, только если прод снова окажется в РФ. Сейчас
сервер за границей, прокси выключен, ретранслятор `tg.<domain>` не используется.

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
  Для отладки руками: `docker compose exec app php artisan vpn:daily-charge --force-notify`.
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
  14 дней (`LOG_DAILY_DAYS`). На проде — `/opt/gatekeeper/storage/logs/`;
  логи веб-сервера и планировщика — `docker compose logs app|scheduler`.
- **Кода на проде нет.** Он в Docker-образе; на сервере в `/opt/gatekeeper`
  только `.env`, база, логи, копии базы и сертификаты. Править код на сервере
  нельзя и не нужно: изменения едут через push в `main`.
- **artisan на проде — только внутри контейнера:**
  `cd /opt/gatekeeper && docker compose exec app php artisan ...`. В скриптах,
  которые идут через `ssh host bash`, добавляй `</dev/null`, иначе `exec`
  съест остаток скрипта.
- **Файлы в `storage` принадлежат uid 33 (www-data).** Правишь руками —
  верни владельца `chown -R 33:33`, иначе приложение не сможет писать.
- **Пароль VPN-клиента = telegram_id юзера.** Это by design, не баг —
  `creatOneClientFromTelegram` передаёт его вторым аргументом. Если у юзера нет
  `telegram_username`, логин генерируется как `c_{user_id}_{rand(111,999)}`.

## Известные проблемы / техдолг

Ничего из этого не горит, но знать полезно. Примерно в порядке серьёзности:

1. **Обсерверы делают синхронные HTTP-вызовы к StrongSwan** из жизненного
   цикла моделей. Логика решения теперь одна (`AccessService`), но вызов всё
   ещё синхронный. На одном сервере с API это локальный запрос, не страшно.
2. **Долг накапливается неограниченно** — списание идёт даже при глубоко
   отрицательном балансе.
3. **`ClientObserver` шлёт Telegram без ретраев**, только try/catch.
4. **Расхождение валюты в текстах:** приветствие говорит «руб/сутки», баланс и
   уведомления — «у.е.», админка показывает «₽». Фактически 1 ₽ = 1 у.е.
5. **Транзакции из админки создаются с `subject_type = null`** — см. «двойной
   бонус» в Тонкостях.
6. **PHP 8.3 и Laravel 12.21** — обновление отложено: совместимость Orchid 14
   с новыми версиями не проверялась.
7. **`AccessService` и экраны админки создают `new VpnService()` напрямую**,
   в обход контейнера — их нельзя подменить в тестах так же, как в `vpn:reconcile`.
8. **Юзер без telegram_id** (заведён на VPN-сервере руками, импортирован без
   Telegram) не получает уведомлений: чата для него нет.
9. **Импорт угадал telegram_id не у всех.** 18.11.2026 Telegram ответил
   «chat not found» на уведомления восьми импортированным юзерам. Тех, у кого
   логин канала совпадает с username в Telegram, бот привяжет сам при /start.
   Остальным (логины вроде `Natalia`, `Papa`) при /start заведётся новый
   аккаунт, а канал останется на старом — так решено, юзеров не просим.
   Старые помечены «старая БД» в списке покупателей; их можно удалить, когда
   появятся новые, или дать каналу заблокироваться по балансу.

## Откаты / kill switch

- **Вернуть предыдущую версию кода:** `ssh deploy@<host> rollback`.
- **Вернуть базу:** копии лежат в `/opt/gatekeeper/backups/` (снимаются перед
  каждым деплоем), порядок восстановления — `infra/app/README.md`.
- **Остановить бота и админку, VPN оставить:**
  `cd /opt/gatekeeper && docker compose stop` — StrongSwan не зависит от контейнеров.
- **Остановить управление VPN, VPN оставить:** `systemctl stop strongswan-api`.
  Подключённые и новые клиенты продолжат работать, блокировки и новые каналы — нет.
- **Остановить VPN целиком:** `systemctl disable --now strongswan-starter`.
- **Переустановить вебхук бота:**
  `docker compose exec app php artisan telegraph:set-webhook 1 --secret=<TELEGRAPH_WEBHOOK_SECRET>`.

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
- Реферальная программа: повышенный бонус приглашённому, бонус приглашающему за активацию друга (выдаёт крон), кнопка «Поделиться» в один тап, напоминания в точках контакта.
- Подготовка к перезапуску после потери БД на Beget (ноябрь 2026): миграции для
  `is_active`, индексов, `external_id` и роли `consumer`; единая формула баланса
  под SQLite; `AccessService` и `PaymentService`; идемпотентные крон и оплата;
  `config/gatekeeper.php` вместо `env()`; права `platform.vpn` в админке,
  удалены демо-экраны Orchid и мёртвый тестовый контроллер; тесты.
- Переезд 17.11.2026: всё на одном сервере вне РФ. StrongSwan в git с новым
  API на unix-сокете, Laravel в Docker (FrankenPHP) с SQLite, автодеплой из
  GitHub Actions с копией базы и автооткатом, 20 юзеров восстановлены из
  `ipsec.secrets` командой `vpn:import-secrets`, ежедневная сверка
  `vpn:reconcile`. Прокси и ретранслятор `tg.<domain>` больше не нужны.
- 17–18.11.2026, после переезда: SQLite в WAL с повтором транзакций на
  `database is locked`; копия базы перед деплоем изнутри контейнера; запасные
  DNS на сервере; локальный дев на прод-образе с демо-данными; отдельный лог
  платежей; исправлено падение письма `NewDeposit`; привязка импортированных
  юзеров к настоящему telegram_id (по username и вручную в админке), пометка
  «старая БД»; роль «Менеджер».

## Тесты

`php artisan test` — гоняется на SQLite в памяти (`phpunit.xml`), сеть закрыта
через `Http::preventStrayRequests()` в `tests/TestCase.php`. Хелперы там же:
`makeConsumer()`, `makeClient()`, `deposit()`, `withdraw()`.

Покрыто: формула баланса и скоуп `withBalance`, ежедневное списание (сумма,
повторный запуск, `--force`, `--user-ids`, блокировка, бонус за активацию),
`AccessService` через обсервер, `PaymentService` (дубли, реферальный процент),
регистрация через вебхук бота (роль, бонусы, занятый username), привязка
импортированных по username и защита подтверждённых аккаунтов от захвата,
ручная привязка telegram_id в карточке покупателя, все пять писем
(`NotificationsTest`), валидация логина и пароля VPN, `vpn:reconcile`
(с подменённым сервером), `vpn:import-secrets`.

API StrongSwan тестируется отдельно, без StrongSwan:
`python3 -m unittest -v infra/strongswan/api/test_strongswan_api.py`.

CI (`.github/workflows/deploy.yml`) гоняет оба набора и проверку синтаксиса
shell-скриптов на каждый push и PR; деплой — только после зелёных тестов.

## Полезные команды

На проде (`ssh root@<host>`, дальше `cd /opt/gatekeeper`):

```bash
docker compose ps                                   # что запущено
docker compose logs -f --tail 100 app               # веб и вебхук
docker compose exec app php artisan schedule:list   # расписание
docker compose exec app php artisan vpn:reconcile   # сверка с VPN-сервером
docker compose exec app php artisan vpn:daily-charge --user-ids=42 --force
docker compose exec app php artisan tinker
tail -f storage/logs/laravel-$(date +%F).log
ls -lt backups/ | head                               # копии базы
cat releases/history                                 # история деплоев

# VPN-сервер
ipsec status | head
journalctl -u strongswan-api -n 50 --no-pager
KEY=$(grep ^API_KEY /etc/strongswan-api.env | cut -d= -f2)
curl -s --unix-socket /run/strongswan-api/api.sock -H "X-Api-Key: $KEY" -X POST http://localhost/sessions

# Что Telegram думает про вебхук
docker compose exec app php artisan tinker --execute='$r = DefStudio\Telegraph\Models\TelegraphBot::find(1)->getWebhookDebugInfo()->send()->json("result"); echo json_encode(["pending" => $r["pending_update_count"] ?? null, "last_error" => $r["last_error_message"] ?? null, "ip" => $r["ip_address"] ?? null]), PHP_EOL;'
```

Локально:

```bash
php artisan test                                                  # можно и в WSL
docker compose exec app php artisan migrate:fresh --seed          # чистая база с демо-данными
docker compose exec app php artisan vpn:daily-charge --force-notify   # VPN-вызовы заглушены
docker compose exec app php artisan optimize:clear                # после правки .env
```
