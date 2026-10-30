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
use App\Support\TelegraphRetry;
use App\Support\Pricing;

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

        // Новые consumer'ы за сутки (и сколько из них пришло по реферальной ссылке)
        $newToday = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->where('created_at', '>=', now()->subDay())->count();
        $newViaReferral = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->where('created_at', '>=', now()->subDay())->whereNotNull('referrer_id')->count();

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
        $referralBonusesToday = 0; // реферальные бонусы за активацию, выданные в этом прогоне

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
                $price = Pricing::forServer($serverName);
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

            // Приглашённый реально потратил деньги на VPN — платим бонус пригласившему
            if ($this->grantReferralActivationBonus($consumer)) {
                $referralBonusesToday++;
            }

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

            // Дней до блокировки — по фактическому суточному расходу этого юзера
            // ($totalCharge уже посчитан выше по его активным клиентам), а не по
            // базовой цене: иначе у юзера с двумя каналами прогноз завышался вдвое.
            $daysLeft = Pricing::daysLeft($balanceAfter, $totalCharge);
            $wasBlocked = $balanceAfter < 0; // пользователь был заблокирован, если баланс отрицательный

            // Отправляем уведомление, если баланс меньше 7 дней или если заблокирован
            $forceNotify = $this->option('force-notify');

            Log::info('[BalanceNotify] Опция --force-notify:', ['value' => $forceNotify ? 'true' : 'false']);

            if ($forceNotify || $wasBlocked || $daysLeft < 7) {
                try {
                    $this->sendBalanceNotification($consumer, $balanceAfter, $daysLeft, $wasBlocked, $forceNotify);
                } catch (\Throwable $e) {
                    Log::error('[BalanceNotify] Не удалось отправить уведомление, продолжаем дальше', [
                        'user_id' => $consumer->id,
                        'telegram_id' => $consumer->telegram_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('NOTIKI -- Before IF to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

        // Отправка уведомления администратору о заблокированных клиентах (по email)
        if ($allBlockedClients->isNotEmpty()) {
            Log::info('NOTIKI -- Preparing to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

            $adminEmail = env('ADMIN_EMAIL');
            if ($adminEmail) {
                try {
                    Notification::route('mail', $adminEmail)
                        ->notify(new ClientsBlocked($allBlockedClients));
                    $this->info('Notification about blocked clients sent to admin.');
                    Log::info('NOTIKI -- Notification about blocked clients sent to admin.', ['count' => $allBlockedClients->count()]);
                } catch (\Throwable $e) {
                    Log::error('NOTIKI -- Failed to send ClientsBlocked email', ['error' => $e->getMessage()]);
                }
            }
        }

        // Отправляем сводку администратору в Telegram
        try {
            $this->sendAdminSummary(
                totalConsumers: $totalConsumers,
                totalClients: $totalClients,
                activeClients: $activeClientsTotal,
                inactiveClients: $inactiveClientsTotal,
                totalCharged: $totalCharged,
                blockedToday: $allBlockedClients->count(),
                newToday: $newToday,
                newViaReferral: $newViaReferral,
                referralBonusesToday: $referralBonusesToday
            );
        } catch (\Throwable $e) {
            Log::error('[AdminSummary] Не удалось отправить сводку администратору в Telegram', ['error' => $e->getMessage()]);
        }

        // Отправляем сводку администратору на email
        $adminEmail = env('ADMIN_EMAIL');
        if ($adminEmail) {
            try {
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
            } catch (\Throwable $e) {
                Log::error('[DailySummary] Не удалось отправить сводку администратору на email', ['error' => $e->getMessage()]);
            }
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
            $text .= "\n\n" . $this->referralNudge();
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
                $text .= "\n\n" . $this->referralNudge();
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

        $keyboard = Keyboard::make()
            ->row([
                Button::make('💳 Пополнить')->action('addbalance')->param('uid', $user->telegram_id)
            ])
            ->row([$this->giftButton($user)]);

        $response = TelegraphRetry::attempt(
            fn() => $chat->message($text)->keyboard($keyboard)->send(),
            5,
            500,
            'BalanceNotify user_id=' . $user->id
        );

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
     * Абзац-подсказка про реферальную программу (подстановка цен только через Pricing)
     */
    private function referralNudge(): string
    {
        return str_replace(
            ['{days}', '{bonus}', '{percent}'],
            [Pricing::referralFreeDays(), Pricing::referralActivationBonus(), Pricing::referralPercent()],
            config('bot.text.referral.nudge')
        );
    }

    /**
     * Реферальная ссылка юзера. Username бота читается через env() — так же, как в
     * Handler::ref(): ключа username в config/telegraph.php нет.
     */
    private function referralLink(User $user): string
    {
        $botUsername = env('TELEGRAPH_BOT_USERNAME');

        return "https://t.me/{$botUsername}?start=ref_{$user->id}";
    }

    /**
     * Ссылка «Поделиться» — открывает в Telegram выбор чата с готовым текстом-приглашением
     */
    private function shareUrl(User $user): string
    {
        $link = $this->referralLink($user);
        $text = str_replace(
            ['{days}', '{link}'],
            [Pricing::referralFreeDays(), $link],
            config('bot.text.referral.share')
        );

        return 'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode($text);
    }

    /**
     * Кнопка «Подарить другу N дней» для уведомлений
     */
    private function giftButton(User $user): Button
    {
        return Button::make(str_replace(
            ['{days}', '{bonus}'],
            [Pricing::referralFreeDays(), Pricing::referralActivationBonus()],
            config('bot.button.gift')
        ))
            ->url($this->shareUrl($user));
    }

    /**
     * Бонус пригласившему за активацию друга (одна выплата на пару навсегда).
     * Возвращает true, если бонус выдан в этом вызове.
     */
    private function grantReferralActivationBonus(User $consumer): bool
    {
        if (empty($consumer->referrer_id)) {
            return false;
        }

        // Идемпотентность: независимо от is_active, повторно за того же друга не платим
        $alreadyGranted = Transaction::where('subject_type', 'referral_activation')
            ->where('subject_id', $consumer->id)
            ->exists();
        if ($alreadyGranted) {
            return false;
        }

        $referrer = User::find($consumer->referrer_id);
        if (!$referrer) {
            Log::warning('[ReferralBonus] Реферер не найден', [
                'user_id' => $consumer->id,
                'referrer_id' => $consumer->referrer_id,
            ]);
            return false;
        }

        $bonus = Pricing::referralActivationBonus();

        try {
            // Deposit триггерит TransactionObserver (может активировать клиентов реферера) — это ожидаемо
            Transaction::createTransaction(
                $referrer->id,
                'deposit',
                $bonus,
                'referral_activation',
                $consumer->id,
                "Бонус за подключение приглашённого #{$consumer->id}"
            );
        } catch (\Throwable $e) {
            Log::error('[ReferralBonus] Не удалось создать транзакцию бонуса', [
                'user_id' => $consumer->id,
                'referrer_id' => $referrer->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->info("Referral bonus {$bonus} granted to #{$referrer->id} for consumer #{$consumer->id}");
        Log::info("[ReferralBonus] Начислено {$bonus} пользователю #{$referrer->id} за активацию #{$consumer->id}");

        // Уведомление — best effort: ошибка не ломает прогон и не откатывает транзакцию
        try {
            $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
            if (!$botId) {
                Log::warning('[ReferralBonus] TELEGRAPH_BOT_NOTIFY_ID не задан в .env');
                return true;
            }

            $chat = TelegraphChat::where('chat_id', $referrer->telegram_id)
                ->where('telegraph_bot_id', $botId)
                ->first();
            if (!$chat) {
                Log::warning('[ReferralBonus] Чат реферера не найден', [
                    'referrer_id' => $referrer->id,
                    'telegram_id' => $referrer->telegram_id,
                    'bot_id' => $botId,
                ]);
                return true;
            }

            // Убираем символы markdown из имени, чтобы не сломать разметку сообщения
            $friendName = str_replace(['_', '*', '`', '['], '', (string) $consumer->name);
            $percent = Pricing::referralPercent();
            $text = "🎉 Ваш друг {$friendName} подключил VPN — вам начислено *{$bonus} у.е.*\n"
                . "Дальше вы получаете {$percent}% с каждого его пополнения.";
            $keyboard = Keyboard::make()->row([$this->giftButton($referrer)]);

            $response = TelegraphRetry::attempt(
                fn() => $chat->message($text)->keyboard($keyboard)->send(),
                5,
                500,
                'ReferralBonus referrer_id=' . $referrer->id
            );

            if ($response->json('ok') !== true) {
                Log::error('[ReferralBonus] Ошибка отправки уведомления', [
                    'referrer_id' => $referrer->id,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[ReferralBonus] Не удалось отправить уведомление рефереру', [
                'referrer_id' => $referrer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
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
    protected function sendAdminSummary(int $totalConsumers, int $totalClients, int $activeClients, int $inactiveClients, float $totalCharged, int $blockedToday, int $newToday = 0, int $newViaReferral = 0, int $referralBonusesToday = 0): void
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
        $text .= "🆕 Новых за сутки: *{$newToday}*\n";
        $text .= "🔗 из них по ссылке: *{$newViaReferral}*\n";
        $text .= "🎁 Реферальных бонусов сегодня: *{$referralBonusesToday}*\n";

        $response = TelegraphRetry::attempt(
            fn() => $chat->message($text)->send(),
            5,
            500,
            'AdminSummary'
        );

        if ($response->json('ok') === true) {
            Log::info('[AdminSummary] Сводка успешно отправлена администратору');
        } else {
            Log::error('[AdminSummary] Ошибка отправки сводки', [
                'response' => $response->json()
            ]);
        }
    }
}
