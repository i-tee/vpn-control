# StrongSwan VPN-сервер

Всё, что нужно, чтобы поднять IKEv2-сервер Gatekeeper с нуля или обновить
существующий. До ноября 2026 этот сервер нигде не был сохранён: конфиги
восстановлены из бэкапа живого сервера.

## Как устроено

```
клиент (iPhone/Mac/Android/Windows)
   │  IKEv2, UDP 500/4500, логин+пароль (EAP-MSCHAPv2)
   ▼
charon (strongswan-starter) ── /etc/ipsec.conf, /etc/ipsec.secrets
   ▲                              ▲
   │ ipsec rereadsecrets / down   │ пишет EAP-строки
   │                              │
strongswan_api.py ── unix-сокет /run/strongswan-api/api.sock
   ▲
   │ HTTP через сокет, ключ в X-Api-Key
Laravel (контейнер, монтирует /run/strongswan-api)
```

- **Сертификат сервера** — Let's Encrypt на домен VPN. Клиент проверяет его по
  «удалённому идентификатору» = домен. Ключ копируется в
  `/etc/ipsec.d/private/privkey.pem`, на него ссылается строка
  `<домен> : RSA "privkey.pem"` в `ipsec.secrets`.
- **Юзеры** — строки `<логин> : EAP "<пароль>"` в `ipsec.secrets`. Пароль канала
  из бота равен telegram_id юзера, это задумано так.
- **Клиенты** получают адреса из `10.250.0.0/16` и выходят в интернет через
  NAT сервера.

## Файлы

| В репозитории | На сервере |
|---|---|
| `api/strongswan_api.py` | `/opt/strongswan-api/strongswan_api.py` |
| `config/strongswan-api.service` | `/etc/systemd/system/strongswan-api.service` |
| `config/strongswan-api.env.example` | `/etc/strongswan-api.env` (ключ API, chmod 600) |
| `config/ipsec.conf.template` | `/etc/ipsec.conf` |
| `config/charon-gatekeeper.conf` | `/etc/strongswan.d/charon-gatekeeper.conf` |
| `config/letsencrypt-deploy-hook.sh` | `/etc/letsencrypt/renewal-hooks/deploy/strongswan.sh` |
| `config/vpn-firewall.sh` + `.service` | `/usr/local/sbin/vpn-firewall.sh`, юнит `vpn-firewall` |
| — | `/etc/ipsec.secrets` — **только на сервере и в бэкапе**, в git никогда |

## Установка или обновление

```bash
# на сервере, из копии репозитория
sudo VPN_DOMAIN=x.example.com LE_EMAIL=admin@example.com ./infra/strongswan/install.sh
```

Скрипт идемпотентный. На живом сервере он не трогает юзеров, не перевыпускает
сертификат и делает `ipsec reload` вместо restart, так что подключённые клиенты
не отваливаются. Перед заменой конфигов кладёт рядом `*.bak-<дата>`.

Когда порт 80 займёт Caddy, продление сертификата переключается на webroot:

```bash
sudo VPN_DOMAIN=x.example.com ACME_MODE=webroot ./infra/strongswan/install.sh
```

Caddy должен отдавать `/.well-known/acme-challenge/*` для домена VPN из
`/var/www/acme`.

## API

Слушает unix-сокет, по сети недоступен. Ключ в заголовке `X-Api-Key`
(или полем `key` в JSON — так ходил старый клиент).

| Запрос | Что делает |
|---|---|
| `GET /health` | `{"status":"ok","charon":true}`, без ключа |
| `POST /get_users` | список логинов |
| `POST /add_user {username, password}` | добавить или сменить пароль, `ipsec rereadsecrets` |
| `POST /remove_user {username}` | удалить и оборвать сессии **только этого** юзера; обрыв через `stroke down-nb`, без ожидания клиента |
| `POST /sessions` | активные сессии `[{sa, identity}]` |

Проверить руками на сервере:

```bash
KEY=$(grep ^API_KEY /etc/strongswan-api.env | cut -d= -f2)
curl -s --unix-socket /run/strongswan-api/api.sock http://localhost/health
curl -s --unix-socket /run/strongswan-api/api.sock -H "X-Api-Key: $KEY" -X POST http://localhost/get_users
journalctl -u strongswan-api -n 50 --no-pager
```

Тесты (без StrongSwan, ipsec подменяется скриптом):

```bash
python3 -m unittest -v infra/strongswan/api/test_strongswan_api.py
```

### Что было не так со старым API (Flask, порт 5000)

- `remove_user` стирал **каждую строку, содержащую** имя. Удаление юзера `a`
  снесло бы строки с ключом сервера и PSK, VPN упал бы у всех.
- Каждое добавление и удаление делало `ipsec restart` — блокировка одного
  должника выкидывала всех подключённых.
- Порт 5000 был открыт всему интернету, ключ летел открытым текстом.
- Логин и пароль писались в файл без проверки: перевод строки в имени
  дописывал в `ipsec.secrets` произвольную строку.

### Почему обрыв сессии неблокирующий

22.11.2026 `ipsec down` ждал, пока клиент подтвердит отключение. Спящий
телефон не отвечает, charon повторяет запрос минутами, и `remove_user`
отвечал дольше таймаута Laravel. Логин из секретов уже был удалён, а в базе
канал оставался активным; через 15 минут `vpn:reconcile --fix` возвращал логин
обратно — должники получали VPN бесплатно. Теперь сессия рвётся
`/usr/lib/ipsec/stroke down-nb` (переменная `STROKE_BIN`), а ошибка обрыва не
делает весь вызов неудачным.

## Файрвол и Docker

`vpn-firewall.service` стартует **после** Docker и идемпотентно добавляет:

- FORWARD: трафик из подсети клиентов с IPsec-политикой и обратно — ACCEPT;
- NAT: MASQUERADE подсети клиентов на внешний интерфейс.

Зачем отдельный юнит: Docker при старте ставит политику FORWARD в DROP. Старые
правила сервера ловили интерфейс `charon`, которого не существует (libipsec
выключен, трафик идёт через XFRM ядра на eth0), и VPN работал только потому,
что политика была ACCEPT. С Docker на том же хосте клиенты потеряли бы интернет.

### Установка Docker на сервер с живым VPN

Docker создаёт интерфейс `docker0` с новым адресом. charon реагирует на смену
адресов проверкой живости всех клиентов сразу, и сессии, которые не ответили
на 5 повторов (спящие телефоны, брошенные подключения), удаляются. 17.11.2026
так ушли 24 из 33 сессий; живые клиенты переподключились сами. Ставить Docker
лучше в тихое время.

`setup-server.sh` пишет в `/etc/docker/daemon.json` `ip-forward-no-drop: true`
до установки, поэтому Docker не трогает политику FORWARD. Правила vpn-firewall
остаются вторым рубежом.

## Бэкап

Терять нельзя: `/etc/ipsec.secrets` (все юзеры), `/etc/strongswan-api.env`
(ключ API), `/etc/letsencrypt` (сертификат и аккаунт ACME). Остальное
восстанавливается из репозитория скриптом установки.

```bash
ssh root@<vpn-host> 'tar czf - /etc/ipsec.secrets /etc/strongswan-api.env /etc/letsencrypt' > strongswan-$(date +%F).tar.gz
```

## Восстановление после потери базы Laravel

Логины и пароли остались в `ipsec.secrets`, пароль = telegram_id. Импорт
восстанавливает юзеров, каналы, стартовый баланс и чаты для уведомлений:

```bash
php artisan vpn:import-secrets /path/to/ipsec.secrets --dry-run
php artisan vpn:import-secrets /path/to/ipsec.secrets --balance=100
```

## Что можно ужесточить потом

- Убрать `modp1024` из `ike`/`esp`. Сломает встроенный IKEv2-клиент Windows с
  настройками по умолчанию.
- MSS clamping для клиентов с маленьким MTU, если появятся жалобы на сайты,
  которые не открываются.
- IKEv2 легко распознаётся DPI. Долгосрочно — второй протокол (VLESS+Reality
  или AmneziaWG).
