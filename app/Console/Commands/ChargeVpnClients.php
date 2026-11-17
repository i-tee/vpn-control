<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccessService;
use App\Notifications\ClientsBlocked;
use Illuminate\Console\Command;
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
    protected $signature = 'vpn:daily-charge
        {--user-ids= : Comma-separated list of user IDs to process (optional)}
        {--force-notify : Send notification regardless of days left}
        {--force : Charge even if the user was already charged today}';

    protected $description = 'Daily charge for active VPN clients, deactivate on negative balance, reactivate on positive';

    public function handle(AccessService $access): int
    {
        $this->info('Starting daily VPN client charge process...');
        Log::info('[DailyCharge] Старт');

        // Общая статистика по системе (до фильтрации)
        $totalConsumers = User::consumers()->count();
        $newToday = User::consumers()->where('created_at', '>=', now()->subDay())->count();
        $newViaReferral = User::consumers()->where('created_at', '>=', now()->subDay())->whereNotNull('referrer_id')->count();

        $consumers = User::consumers()->get();

        // Фильтр по ID, если передан --user-ids
        if ($userIdsOption = $this->option('user-ids')) {
            $userIds = array_filter(array_map('trim', explode(',', $userIdsOption)), 'is_numeric');
            if (!empty($userIds)) {
                $consumers = $consumers->filter(fn($user) => in_array($user->id, $userIds));
                $this->info("Filtered to {$consumers->count()} consumers by provided IDs.");
            } else {
                $this->warn('No valid numeric IDs found in --user-ids. Processing all consumers.');
            }
        }

        $this->info("Found {$consumers->count()} consumers to process");
        $totalCharged = 0;
        $allBlockedClients = new Collection();
        $referralBonusesToday = 0;
        $skippedAlreadyCharged = 0;

        foreach ($consumers as $consumer) {
            $activeClients = Client::where('user_id', $consumer->id)->where('is_active', true)->get();

            if ($activeClients->isEmpty()) {
                continue;
            }

            // Идемпотентность: повторный запуск в тот же день не списывает второй раз
            if (!$this->option('force') && $this->alreadyChargedToday($consumer)) {
                $skippedAlreadyCharged++;
                $this->line("Consumer #{$consumer->id} already charged today, skipping");
                continue;
            }

            $this->line("Processing consumer #{$consumer->id} ({$consumer->name}) with {$activeClients->count()} active clients");

            // Сумма списания по каждому серверу
            $serverDetails = [];
            foreach ($activeClients as $client) {
                $price = Pricing::forServer($client->server_name);
                $serverDetails[$client->server_name]['price'] = $price;
                $serverDetails[$client->server_name]['count'] = ($serverDetails[$client->server_name]['count'] ?? 0) + 1;
            }
            $totalCharge = array_sum(array_map(fn($d) => $d['price'] * $d['count'], $serverDetails));

            $commentDetails = [];
            foreach ($serverDetails as $serverName => $details) {
                $commentDetails[] = "{$details['count']} active client(s) on {$serverName} ({$details['price']} each)";
            }

            Transaction::createTransaction(
                $consumer->id,
                'withdraw',
                $totalCharge,
                'vpn_service',
                null,
                'Daily charge for ' . implode('; ', $commentDetails) . ". Total: {$totalCharge}"
            );

            $this->info("Charged {$totalCharge} from consumer #{$consumer->id}");
            $totalCharged += $totalCharge;

            // Приглашённый реально потратил деньги на VPN — платим бонус пригласившему
            if ($this->grantReferralActivationBonus($consumer)) {
                $referralBonusesToday++;
            }

            // Включаем или блокируем каналы по новому балансу
            $sync = $access->sync($consumer);
            $balanceAfter = $sync['balance'];
            $allBlockedClients = $allBlockedClients->merge($sync['deactivated']);
            $this->line("Consumer #{$consumer->id} balance after charge: {$balanceAfter}");

            // Дней до блокировки — по фактическому суточному расходу этого юзера
            $daysLeft = Pricing::daysLeft($balanceAfter, $totalCharge);
            $wasBlocked = $balanceAfter < 0;
            $forceNotify = (bool) $this->option('force-notify');

            if ($forceNotify || $wasBlocked || $daysLeft < 7) {
                try {
                    $this->sendBalanceNotification($consumer, $balanceAfter, $daysLeft, $wasBlocked, $forceNotify);
                } catch (\Throwable $e) {
                    Log::error('[BalanceNotify] Не удалось отправить уведомление, продолжаем дальше', [
                        'user_id' => $consumer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Статистика по клиентам — после прогона, с учётом блокировок
        $totalClients = Client::count();
        $activeClientsTotal = Client::where('is_active', true)->count();
        $inactiveClientsTotal = $totalClients - $activeClientsTotal;

        if ($allBlockedClients->isNotEmpty() && ($adminEmail = config('gatekeeper.admin_email'))) {
            try {
                Notification::route('mail', $adminEmail)->notify(new ClientsBlocked($allBlockedClients));
            } catch (\Throwable $e) {
                Log::error('[DailyCharge] Не удалось отправить письмо ClientsBlocked', ['error' => $e->getMessage()]);
            }
        }

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

        if ($adminEmail = config('gatekeeper.admin_email')) {
            try {
                Notification::route('mail', $adminEmail)->notify(new DailySummary(
                    totalConsumers: $totalConsumers,
                    totalClients: $totalClients,
                    activeClients: $activeClientsTotal,
                    inactiveClients: $inactiveClientsTotal,
                    totalCharged: $totalCharged,
                    blockedToday: $allBlockedClients->count()
                ));
            } catch (\Throwable $e) {
                Log::error('[DailySummary] Не удалось отправить сводку администратору на email', ['error' => $e->getMessage()]);
            }
        }

        $this->info("Daily charge process completed. Total charged: {$totalCharged}");
        Log::info("[DailyCharge] Готово. Списано: {$totalCharged}, пропущено как уже списанных: {$skippedAlreadyCharged}");

        return Command::SUCCESS;
    }

    /**
     * Было ли у юзера сегодня ежедневное списание.
     */
    private function alreadyChargedToday(User $user): bool
    {
        return Transaction::where('user_id', $user->id)
            ->where('type', 'withdraw')
            ->where('subject_type', 'vpn_service')
            ->where('is_active', true)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
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

        $botId = config('gatekeeper.notify_bot_id');
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
     * Реферальная ссылка юзера. Username бота берётся из config('gatekeeper.bot_username').
     */
    private function referralLink(User $user): string
    {
        $botUsername = config('gatekeeper.bot_username');

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
            $botId = config('gatekeeper.notify_bot_id');
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
        $botId = config('gatekeeper.notify_bot_id');
        $adminChatId = config('gatekeeper.admin_chat_id');

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
