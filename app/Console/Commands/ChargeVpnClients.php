<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VpnService;
use App\Notifications\ClientsBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use App\Notifications\DailySummary;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\Button;

class ChargeVpnClients extends Command
{

    protected $signature = 'vpn:daily-charge {--user-ids= : Comma-separated list of user IDs to process (optional)} {--force-notify : Send notification regardless of days left}';
    protected $description = 'Daily charge for active VPN clients, deactivate on negative balance, reactivate on positive';

    protected VpnService $vpnService;

    public function __construct(VpnService $vpnService)
    {
        parent::__construct();
        $this->vpnService = $vpnService;
    }

    public function handle()
    {
        $this->info('Starting daily VPN client charge process...');
        Log::info('NOTIKI -- Starting daily VPN client charge process');

        // Общая статистика по системе (до фильтрации)
        $totalConsumers = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->count();

        $totalClients = Client::count();
        $activeClientsTotal = Client::where('is_active', true)->count();
        $inactiveClientsTotal = Client::where('is_active', false)->count();

        // Получаем всех потребителей для обработки
        $consumers = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->get();

        // Применяем фильтр по ID, если передан параметр --user-ids
        $userIdsOption = $this->option('user-ids');
        if ($userIdsOption) {
            $userIds = explode(',', $userIdsOption);
            $userIds = array_map('trim', $userIds);
            $userIds = array_filter($userIds, 'is_numeric');
            if (!empty($userIds)) {
                $consumers = $consumers->filter(fn($user) => in_array($user->id, $userIds));
                $this->info("Filtered to " . $consumers->count() . " consumers by provided IDs.");
            } else {
                $this->warn("No valid numeric IDs found in --user-ids. Processing all consumers.");
            }
        }

        $this->info("Found {$consumers->count()} consumers to process");
        $totalCharged = 0;
        $allBlockedClients = new Collection();
        $totalActiveClients = 0; // для статистики по обработанным

        foreach ($consumers as $consumer) {
            $activeClients = Client::where('user_id', $consumer->id)
                ->where('is_active', true)
                ->get();

            if ($activeClients->isEmpty()) {
                continue;
            }

            $this->line("Processing consumer #{$consumer->id} ({$consumer->name}) with {$activeClients->count()} active clients");

            $totalCharge = 0;
            $serverDetails = [];

            $totalActiveClients += $activeClients->count();

            // Расчёт суммы списания по каждому клиенту
            foreach ($activeClients as $client) {
                $serverName = $client->server_name;
                $serversConfig = Config::get('vpn.servers', []);
                $price = $serversConfig[$serverName]['price'] ?? Config::get('vpn.default_price', 1);
                $totalCharge += $price;

                if (!isset($serverDetails[$serverName])) {
                    $serverDetails[$serverName] = [
                        'count' => 1,
                        'price' => $price,
                        'total' => $price,
                    ];
                } else {
                    $serverDetails[$serverName]['count']++;
                    $serverDetails[$serverName]['total'] += $price;
                }
            }

            // Создаём транзакцию списания
            $commentDetails = [];
            foreach ($serverDetails as $serverName => $details) {
                $commentDetails[] = "{$details['count']} active client(s) on {$serverName} ({$details['price']} each)";
            }
            $comment = "Daily charge for " . implode('; ', $commentDetails) . ". Total: {$totalCharge}";

            Transaction::createTransaction(
                $consumer->id,
                'withdraw',
                $totalCharge,
                'vpn_service',
                null,
                $comment
            );

            $this->info("Charged {$totalCharge} from consumer #{$consumer->id}");
            Log::info("NOTIKI -- Charged {$totalCharge} from consumer #{$consumer->id}");
            $totalCharged += $totalCharge;

            // Проверяем баланс после списания
            $balanceAfter = $this->getUserBalance($consumer);
            $this->line("Consumer #{$consumer->id} balance after charge: {$balanceAfter}");

            if ($balanceAfter < 0) {
                // Баланс отрицательный – деактивируем всех активных клиентов
                $this->warn("Negative balance for consumer #{$consumer->id}. Deactivating clients...");
                foreach ($activeClients as $client) {
                    try {
                        // Используем сервис с сервером конкретного клиента
                        $vpn = new VpnService($client->server_name);
                        $vpn->deactivateClient($client->id);
                        $allBlockedClients->push($client);
                        $this->line("Deactivated client #{$client->id} ({$client->name})");
                        Log::warning("NOTIKI -- Deactivated client #{$client->id} for user #{$consumer->id} due to negative balance");
                    } catch (\Exception $e) {
                        $this->error("Failed to deactivate client #{$client->id}: " . $e->getMessage());
                        Log::error("NOTIKI -- Failed to deactivate client #{$client->id}: " . $e->getMessage());
                    }
                }
            } else {
                // Баланс неотрицательный – активируем всех неактивных клиентов (если были)
                $inactiveClients = Client::where('user_id', $consumer->id)
                    ->where('is_active', false)
                    ->get();

                if ($inactiveClients->isNotEmpty()) {
                    $this->info("Positive balance for consumer #{$consumer->id}. Activating inactive clients...");
                    foreach ($inactiveClients as $client) {
                        try {
                            $vpn = new VpnService($client->server_name);
                            $vpn->activateClient($client->id);
                            $this->line("Activated client #{$client->id} ({$client->name})");
                            Log::info("Activated client #{$client->id} for user #{$consumer->id} due to positive balance");
                        } catch (\Exception $e) {
                            $this->error("Failed to activate client #{$client->id}: " . $e->getMessage());
                            Log::error("Failed to activate client #{$client->id}: " . $e->getMessage());
                        }
                    }
                }
            }

            // Рассчитываем количество дней до блокировки (положительный остаток)
            $daysLeft = $balanceAfter > 0 ? floor($balanceAfter / config('vpn.default_price')) : 0;
            $wasBlocked = $balanceAfter < 0; // пользователь был заблокирован, если баланс отрицательный

            // Отправляем уведомление, если баланс меньше 7 дней или если заблокирован
            $forceNotify = $this->option('force-notify');

            Log::info('[BalanceNotify] Опция --force-notify:', ['value' => $forceNotify ? 'true' : 'false']);

            if ($forceNotify || $wasBlocked || $daysLeft < 7) {
                $this->sendBalanceNotification($consumer, $balanceAfter, $daysLeft, $wasBlocked, $forceNotify);
            }
        }

        Log::info('NOTIKI -- Before IF to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

        // Отправка уведомления администратору о заблокированных клиентах (по email)
        if ($allBlockedClients->isNotEmpty()) {
            Log::info('NOTIKI -- Preparing to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

            $adminEmail = env('ADMIN_EMAIL');
            if ($adminEmail) {
                Notification::route('mail', $adminEmail)
                    ->notify(new ClientsBlocked($allBlockedClients));
                $this->info('Notification about blocked clients sent to admin.');
                Log::info('NOTIKI -- Notification about blocked clients sent to admin.', ['count' => $allBlockedClients->count()]);
            }
        }

        // Отправляем сводку администратору в Telegram
        $this->sendAdminSummary(
            totalConsumers: $totalConsumers,
            totalClients: $totalClients,
            activeClients: $activeClientsTotal,
            inactiveClients: $inactiveClientsTotal,
            totalCharged: $totalCharged,
            blockedToday: $allBlockedClients->count()
        );

        // Отправляем сводку администратору на email
        $adminEmail = env('ADMIN_EMAIL');
        if ($adminEmail) {
            Notification::route('mail', $adminEmail)
                ->notify(new \App\Notifications\DailySummary(
                    totalConsumers: $totalConsumers,
                    totalClients: $totalClients,
                    activeClients: $activeClientsTotal,
                    inactiveClients: $inactiveClientsTotal,
                    totalCharged: $totalCharged,
                    blockedToday: $allBlockedClients->count()
                ));
            Log::info('[DailySummary] Сводка отправлена на email', ['email' => $adminEmail]);
        }

        $this->info("Daily charge process completed. Total charged: {$totalCharged}");
        Log::info("NOTIKI -- Daily charge process completed. Total charged: {$totalCharged}");

        return Command::SUCCESS;
    }

    /**
     * Получить баланс пользователя
     */
    private function getUserBalance(User $user): float
    {
        return (float) Transaction::where('user_id', $user->id)
            ->where('is_active', true)
            ->selectRaw('SUM(CASE WHEN type = "deposit" THEN amount ELSE -amount END) as balance')
            ->value('balance') ?? 0;
    }

    /**
     * Отправить уведомление пользователю о балансе и блокировке
     */
    protected function sendBalanceNotification(User $user, float $balance, int $daysLeft, bool $isBlocked, bool $force = false): void
    {
        Log::info('[BalanceNotify] Вызван sendBalanceNotification', [
            'user_id' => $user->id,
            'balance' => $balance,
            'days_left' => $daysLeft,
            'isBlocked' => $isBlocked,
            'force' => $force ? 'true' : 'false'
        ]);

        $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
        if (!$botId) {
            Log::error('[BalanceNotify] TELEGRAPH_BOT_NOTIFY_ID не задан в .env');
            return;
        }

        $chat = TelegraphChat::where('chat_id', $user->telegram_id)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) {
            Log::warning('[BalanceNotify] Чат не найден', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'bot_id' => $botId
            ]);
            return;
        }

        Log::info('[BalanceNotify] Чат найден', ['chat_id' => $chat->chat_id, 'bot_id' => $botId]);

        if ($isBlocked) {
            $text = "🚫 *Ваш VPN-доступ заблокирован*\n\n";
            $text .= "💰 Баланс: {$balance} у.е.\n";
            $text .= "Для восстановления доступа пополните баланс.";
        } elseif ($daysLeft < 7 || $force) {
            if ($force && $daysLeft >= 7) {
                $text = "ℹ️ *Тестовое уведомление*\n\n";
                $text .= "У вас достаточно средств на *{$daysLeft} дней*.\n";
                $text .= "💰 Баланс: {$balance} у.е.\n";
                $text .= "Это тестовое сообщение, отправленное с флагом --force-notify.";
            } else {
                $text = "⚠️ *Внимание!*\n\n";
                $text .= "У вас осталось *{$daysLeft} " . $this->pluralDays($daysLeft) . "* до блокировки сервиса.\n";
                $text .= "💰 Баланс: {$balance} у.е.\n";
                $text .= "Рекомендуем пополнить баланс.";
            }
        } else {
            Log::info('[BalanceNotify] Условия не выполнены, отправка не требуется', [
                'daysLeft' => $daysLeft,
                'isBlocked' => $isBlocked,
                'force' => $force
            ]);
            return;
        }

        Log::info('[BalanceNotify] Попытка отправить сообщение', ['text' => $text]);

        $keyboard = Keyboard::make()->row([
            Button::make('💳 Пополнить')->action('addbalance')->param('uid', $user->telegram_id)
        ]);

        $response = $chat->message($text)
            ->keyboard($keyboard)
            ->send();

        if ($response->json('ok') === true) {
            Log::info('[BalanceNotify] Уведомление успешно отправлено', [
                'user_id' => $user->id,
                'message_id' => $response->json('result.message_id')
            ]);
        } else {
            Log::error('[BalanceNotify] Ошибка отправки уведомления', [
                'user_id' => $user->id,
                'response' => $response->json()
            ]);
        }
    }

    /**
     * Вспомогательный метод для склонения слова "день"
     */
    protected function pluralDays(int $days): string
    {
        if ($days % 10 == 1 && $days % 100 != 11) {
            return 'день';
        } elseif ($days % 10 >= 2 && $days % 10 <= 4 && ($days % 100 < 10 || $days % 100 >= 20)) {
            return 'дня';
        } else {
            return 'дней';
        }
    }

    /**
     * Отправить сводку администратору в Telegram
     */
    protected function sendAdminSummary(int $totalConsumers, int $totalClients, int $activeClients, int $inactiveClients, float $totalCharged, int $blockedToday): void
    {
        $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
        $adminChatId = env('ADMIN_CHAT_ID');

        if (!$botId || !$adminChatId) {
            Log::warning('[AdminSummary] TELEGRAPH_BOT_NOTIFY_ID или ADMIN_CHAT_ID не заданы в .env');
            return;
        }

        $chat = TelegraphChat::where('chat_id', $adminChatId)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) {
            Log::warning('[AdminSummary] Чат администратора не найден', [
                'chat_id' => $adminChatId,
                'bot_id' => $botId
            ]);
            return;
        }

        $text = "📊 *Отчёт о ежедневном списании*\n\n";
        $text .= "👥 Всего потребителей: *{$totalConsumers}*\n";
        $text .= "🔑 Всего клиентов: *{$totalClients}*\n";
        $text .= "   ✅ Активных: *{$activeClients}*\n";
        $text .= "   ❌ Неактивных: *{$inactiveClients}*\n";
        $text .= "💰 Списано сегодня: *{$totalCharged} у.е.*\n";
        $text .= "🚫 Заблокировано сегодня: *{$blockedToday}*\n";

        $response = $chat->message($text)->send();

        if ($response->json('ok') === true) {
            Log::info('[AdminSummary] Сводка успешно отправлена администратору');
        } else {
            Log::error('[AdminSummary] Ошибка отправки сводки', [
                'response' => $response->json()
            ]);
        }
    }
}
