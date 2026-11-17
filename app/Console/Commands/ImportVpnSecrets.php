<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VpnService;
use App\Support\Pricing;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Разовый импорт юзеров из /etc/ipsec.secrets в пустую базу.
 *
 * Нужен после потери базы: на VPN-сервере остались логины и пароли, а пароль
 * канала по задумке равен telegram_id юзера. Из этого восстанавливаются
 * юзер, его канал и чат для уведомлений бота.
 *
 * Идемпотентно: каналы, которые уже есть в базе, пропускаются.
 * Обсерверы на время импорта выключены: никаких писем, сообщений и запросов
 * к VPN-серверу, юзеры там уже есть.
 */
class ImportVpnSecrets extends Command
{
    protected $signature = 'vpn:import-secrets
        {file : Path to a copy of ipsec.secrets}
        {--balance= : Starting balance for each imported user (default: entry bonus)}
        {--server= : Server name from config/vpn.php (default: vpn.default_server)}
        {--dry-run : Show what would be imported without writing}';

    protected $description = 'Import EAP users from ipsec.secrets into an empty database after data loss';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!is_readable($file)) {
            $this->error("Cannot read {$file}");
            return Command::FAILURE;
        }

        $server = $this->option('server') ?: config('vpn.default_server');
        $balance = $this->option('balance') !== null ? (float) $this->option('balance') : Pricing::entryBonus();
        $dryRun = (bool) $this->option('dry-run');
        $botId = config('gatekeeper.notify_bot_id');

        $entries = $this->parse(file_get_contents($file));
        $this->info(count($entries) . " EAP users found in {$file}");

        $rows = [];
        $imported = 0;

        foreach ($entries as [$login, $password]) {
            if (Client::where('name', $login)->exists()) {
                $rows[] = [$login, '-', 'skip: channel exists'];
                continue;
            }

            // Пароль из одних цифр — это telegram_id (так создаёт каналы бот)
            $telegramId = preg_match('/^\d{5,}$/', $password) ? (int) $password : null;

            if ($dryRun) {
                $rows[] = [$login, $telegramId ?? '—', 'would import'];
                continue;
            }

            Model::withoutEvents(function () use ($login, $password, $telegramId, $server, $balance, $botId) {
                DB::transaction(function () use ($login, $password, $telegramId, $server, $balance, $botId) {
                    $user = $telegramId ? User::where('telegram_id', $telegramId)->first() : null;

                    if (!$user) {
                        // c_<id>_<rand> — сгенерированный логин, это не telegram username
                        $isGenerated = (bool) preg_match('/^c_\d+_\d+$/', $login);
                        $username = !$isGenerated && !User::where('telegram_username', $login)->exists() ? $login : null;

                        $user = User::create([
                            'name' => $login,
                            'email' => ($username ?: 'import_' . $login) . '@' . $server,
                            'password' => bcrypt($telegramId ? (string) $telegramId : str()->random(32)),
                            'telegram_id' => $telegramId,
                            'telegram_username' => $username,
                        ]);
                        $user->roles()->attach(User::consumerRole()->id);

                        // entry_bonus — чтобы /start потом не начислил вступительный бонус ещё раз
                        if ($balance > 0) {
                            Transaction::createTransaction(
                                userId: $user->id,
                                type: 'deposit',
                                amount: $balance,
                                subjectType: 'entry_bonus',
                                comment: 'Стартовый баланс после переезда',
                            );
                        }
                    }

                    Client::create([
                        'name' => $login,
                        'password' => $password,
                        'user_id' => $user->id,
                        'server_name' => $server,
                        'telegram_nickname' => $login,
                        'is_active' => true,
                    ]);

                    // Без чата бот не сможет прислать предупреждение о балансе
                    if ($telegramId && $botId) {
                        $exists = TelegraphChat::where('chat_id', (string) $telegramId)
                            ->where('telegraph_bot_id', $botId)
                            ->exists();
                        if (!$exists) {
                            // telegraph_bot_id не fillable в модели пакета
                            (new TelegraphChat())->forceFill([
                                'chat_id' => (string) $telegramId,
                                'telegraph_bot_id' => $botId,
                                'name' => "[private] {$login}",
                            ])->save();
                        }
                    }
                }, 3); // повтор на "database is locked": приложение в это время тоже пишет в базу
            });

            $imported++;
            $rows[] = [$login, $telegramId ?? '—', 'imported'];
        }

        $this->table(['login', 'telegram_id', 'result'], $rows);

        if (!$dryRun) {
            $this->info("Imported {$imported}, starting balance {$balance} each");
            if (!$botId) {
                $this->warn('TELEGRAPH_BOT_NOTIFY_ID is empty: chats were not created, users will not get notifications until they press /start');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{0: string, 1: string}> пары [логин, пароль]
     */
    private function parse(string $content): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $content) as $line) {
            if (!preg_match('/^\s*([^\s:"]+)\s*:\s*EAP\s+"([^"]*)"\s*$/', $line, $m)) {
                continue;
            }
            [, $login, $password] = $m;

            if (!preg_match(VpnService::USERNAME_PATTERN, $login) || !preg_match(VpnService::PASSWORD_PATTERN, $password)) {
                $this->warn("Skipping invalid entry: {$login}");
                continue;
            }
            $entries[$login] = [$login, $password]; // дубли схлопываются, последний выигрывает
        }

        return array_values($entries);
    }
}
