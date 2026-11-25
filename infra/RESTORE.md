# Восстановление Gatekeeper с нуля

**Статус на 25.11.2026: проект заморожен, сервер удаляется.** Причина: с 22.11
российские провайдеры блокируют IKEv2. Сначала МТС заблокировал IP сервера,
новый IP заблокировали меньше чем за час после первых попыток подключения.
Значит, режут сам протокол, и сменой IP или страны сервера это не лечится.
Перед повторным запуском для российских юзеров нужен протокол, который не
ловится DPI (VLESS + Reality или AmneziaWG).

## Что где лежит

| Что | Где |
|---|---|
| код, Dockerfile, деплой, StrongSwan, документация | этот репозиторий |
| база, `.env` прода, юзеры VPN, ключ API, сертификат | зашифрованный архив `gatekeeper-backup-YYYYMMDD.tar.gz.gpg` — **не в git**, в WSL владельца: `~/backups/` |
| пароль от архива | `~/.gatekeeper-backup-passphrase` в WSL владельца |
| ключ деплоя для GitHub Actions | `~/.ssh/gatekeeper_deploy` в WSL владельца |

В архиве база с именами и telegram_id юзеров, пароли VPN-каналов, токены бота
и ЮKassa, пароль почты, `APP_KEY`. Репозиторий публичный — архив и пароль в
него не класть. Держать архив и пароль в разных местах (например, архив в
облаке, пароль в менеджере паролей).

## Восстановление

1. **Сервер:** Ubuntu 22.04 или 24.04, от 1 ГБ памяти, вне РФ.
2. **DNS:** записи A для домена VPN и домена админки (из `.env` архива:
   `VPN_DOMAIN`, `APP_DOMAIN`) — на новый IP. Подождать, пока разойдётся.
3. **Скопировать на сервер** архив и файл с паролем, склонировать репозиторий:
   ```bash
   scp ~/backups/gatekeeper-backup-*.tar.gz.gpg ~/.gatekeeper-backup-passphrase root@<IP>:/root/
   ssh root@<IP> git clone https://github.com/i-tee/vpn-control.git /opt/gatekeeper-src
   ```
4. **Запустить:**
   ```bash
   cd /opt/gatekeeper-src
   sudo BACKUP=/root/gatekeeper-backup-20261125.tar.gz.gpg \
        BACKUP_PASSPHRASE_FILE=/root/.gatekeeper-backup-passphrase \
        DEPLOY_PUBKEY="ssh-ed25519 AAAA... github-actions-gatekeeper-deploy" \
        ./infra/restore.sh
   ```
   `DEPLOY_PUBKEY` — содержимое `~/.ssh/gatekeeper_deploy.pub`.
   Скрипт ставит StrongSwan, возвращает юзеров VPN и ключ API, ставит Docker,
   возвращает `.env` и базу, собирает образ прямо на сервере, деплоит, ставит
   вебхук бота, переключает продление сертификата VPN на Caddy и делает сверку.
5. **GitHub:** секреты `DEPLOY_HOST` и `DEPLOY_KNOWN_HOSTS` под новый IP,
   переменная `DEPLOY_ENABLED=true`.
6. **Удалить с сервера** архив и файл с паролем из `/root`.

`infra/restore.sh` целиком не прогонялся: он собран из шагов, которые
выполнялись руками при переезде 17.11.2026 (`infra/strongswan/install.sh`,
`infra/app/setup-server.sh`, `infra/app/deploy.sh`). Если упадёт на каком-то
шаге — следующие шаги описаны в `infra/strongswan/README.md` и `infra/app/README.md`.

## Что учесть при запуске

- Сертификат Let's Encrypt для домена VPN в архиве действует до 06.01.2027.
  Позже `install.sh` выпустит новый сам — для этого DNS должен смотреть на сервер.
- Балансы в базе — на 25.11.2026, 13:30 МСК (после ежедневного списания).
  Крон спишет за дни простоя только с момента запуска: списание идёт раз в
  сутки по активным каналам, задним числом не начисляется.
- Если домены другие — поменять `APP_DOMAIN`, `APP_URL`, `VPN_DOMAIN` в `.env`
  после восстановления, а в профилях юзеров адрес сервера.
