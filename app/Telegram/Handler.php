<?php

namespace App\Telegram;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use App\Models\User;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Support\Facades\Log;
use Orchid\Platform\Models\Role;
use App\Models\Transaction;
use DefStudio\Telegraph\DTO\PreCheckoutQuery;
use DefStudio\Telegraph\DTO\SuccessfulPayment;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Http;

class Handler extends WebhookHandler
{
    /* ------------------------- 1. Точка входа (/start) ------------------------- */
    public function start(): void
    {
        $from = $this->message->from();
        $text = $this->message->text();

        if ($from->isBot()) {
            $this->reply('Боты не могут регистрироваться.');
            return;
        }

        $user = User::where('telegram_id', $from->id())->first();

        if ($user) {
            $this->greetExisting();
            return;
        }

        // Парсим реферальный параметр
        $referrerId = null;
        if (str_starts_with($text, '/start ref_')) {
            $refParam = substr($text, 7); // убираем '/start '
            if (str_starts_with($refParam, 'ref_')) {
                $refId = (int) substr($refParam, 4);
                // Проверяем, что такой пользователь существует
                if (User::where('id', $refId)->exists()) {
                    $referrerId = $refId;
                    Log::info('[Referral] Новый пользователь пришёл по реферальной ссылке', [
                        'referrer_id' => $referrerId,
                        'new_user_telegram' => $from->id()
                    ]);
                }
            }
        }

        // Регистрируем нового пользователя с referrer_id
        $user = $this->registerUser($from, $referrerId);
        $this->greetNewcomer($from);
        $this->awardBonus($user);

        // Если есть реферер, можно отправить ему уведомление (опционально)
        if ($referrerId) {
            $this->notifyReferrerAboutNewUser($referrerId, $user);
        }
    }

    public function balance(): void
    {
        $this->showbalance();
    }

    public function myvpn(): void
    {
        $this->myClients();
    }

    public function instructions(): void
    {
        $this->instructionsGagets();
    }

    public function support(): void
    {
        $this->chat->message(config('bot.text.needahelp'))
            ->keyboard(
                Keyboard::make()
                    ->row([Button::make(config('bot.button.support'))->url(config('bot.link.support'))])
                    ->row($this->menuButton())
            )
            ->send();
    }

    /* ------------------------- 2. Приветствие нового пользователя ------------------------- */
    private function greetNewcomer(\DefStudio\Telegraph\DTO\User $from): void
    {
        $price = config('vpn.default_price', 12);
        $bonus = config('vpn.entry_bonus', 360);
        $daysFree = ceil($bonus / $price); // дней бесплатно

        // Получаем текст приветствия из конфига
        $welcomeText = config('bot.text.welcome');

        // Заменяем плейсхолдеры
        $replacements = [
            '{price}' => $price,
            '{bonus}' => $bonus,
            '{days}'  => $daysFree,
        ];
        $welcomeText = str_replace(array_keys($replacements), array_values($replacements), $welcomeText);

        // Формируем полное сообщение
        $fullMessage = "👋 Привет, *{$from->firstName()}!*\n\n" . $welcomeText;

        $this->chat->message($fullMessage)
            ->keyboard(
                Keyboard::make()
                    ->row([Button::make(config('bot.button.free_test'))->action('createCanal')])
                    ->row([
                        Button::make(config('bot.button.instruction'))->action('instructionsGagets'),
                        Button::make(config('bot.button.support'))->url(config('bot.link.support'))
                    ])
            )
            ->send();
    }

    /* ------------------------- 3. Регистрация нового пользователя ------------------------- */
    private function registerUser(\DefStudio\Telegraph\DTO\User $from, ?int $referrerId = null): User
    {
        $server = config('vpn.default_server');
        $name = trim($from->firstName() . ' ' . ($from->lastName() ?? ''))
            ?: 'TG_User_' . $from->id();

        $user = User::create([
            'telegram_id'         => $from->id(),
            'telegram_first_name' => $from->firstName(),
            'telegram_last_name'  => $from->lastName() ?: null,
            'telegram_username'   => $from->username() ?: null,
            'name'                => $name,
            'email'               => ($from->username() ?: 'tg_' . $from->id()) . "@$server",
            'password'            => bcrypt((string)$from->id()),
            'referrer_id'         => $referrerId,
        ]);

        $role = Role::where('slug', 'consumer')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /* ------------------------- 4. Приветствие существующего пользователя ------------------------- */
    private function greetExisting(): void
    {
        // Работает как из /start (message), так и из кнопки (callbackQuery)
        $from = $this->message?->from() ?? $this->callbackQuery?->from();
        if (!$from) return;

        $firstName = $from->firstName();
        $rows = [];

        // 1-я строка: «Создать» или «Мой канал»
        $firstRow = [];
        if ($this->user_clients_count() >= 1) {
            $firstRow[] = Button::make(config('bot.text.myclients'))->action('myClients');
        } else {
            $firstRow[] = Button::make(config('bot.text.creat'))->action('createCanal');
        }
        if ($firstRow) {
            $rows[] = $firstRow;
        }

        // 2-я строка: ссылки на инструкцию и поддержку
        $rows[] = [
            Button::make(config('bot.button.instruction'))->action('instructionsGagets'),
            Button::make(config('bot.button.support'))->url(config('bot.link.support'))
        ];

        // 3-я строка

        $rows[] = [
            Button::make('🤝 Реферальная программа')->action('ref'),
        ];

        // 4-я строка: баланс и пополнение
        $rows[] = [
            Button::make('Баланс')->action('showbalance'),
            Button::make('Пополнить')->action('addbalance')->param('uid', $from->id()),
        ];

        // Собираем клавиатуру и отправляем
        $keyboard = Keyboard::make();
        foreach ($rows as $row) {
            $keyboard = $keyboard->row($row);
        }

        $this->chat->message('📌 Главное меню')
            ->keyboard($keyboard)
            ->send();
    }

    /* ------------------------- 5. Начисление вступительного бонуса ------------------------- */
    private function awardBonus(User $user): void
    {
        $bonus = config('vpn.entry_bonus');
        try {
            Transaction::createTransaction(
                userId: $user->id,
                type: 'deposit',
                amount: $bonus,
                comment: 'Вступительный бонус'
            );
            $this->reply("🎉 Вам начислен вступительный бонус {$bonus} у.е.!");
        } catch (\Exception $e) {
            Log::error('Ошибка начисления бонуса', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------- 6. Action-методы (кнопки) ------------------------- */
    public function showbalance(): void
    {
        $user_balance = $this->getBalance();
        $this->chat->message(
            "💼 *Ваш баланс:* {$user_balance} у.е.\n" .
                "📉 Расход: " . config('vpn.default_price') . " у.е./сутки\n" .
                "⏳ Ещё дней: " . ceil($user_balance / config('vpn.default_price'))
        )
            ->keyboard(
                Keyboard::make()
                    ->row([Button::make('➕ Пополнить баланс')->action('addbalance')])
                    ->row($this->menuButton())
            )
            ->send();
    }

    //instructionsGagets
    public function instructionsGagets(): void
    {
        $this->chat->message('📋 Выбери своё устройство — настройка займёт 1 минуту:')
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make('📱 iPhone / iPad')->action('instructions_apple'),
                        Button::make('🍎 Mac')->action('instructions_mac'),
                    ])
                    ->row([
                        Button::make('🤖 Android (Samsung и др.)')->action('instructions_adroid'),
                    ])
                    ->row([
                        Button::make('💬 Другое — написать в поддержку')->url(config('bot.link.support')),
                    ])
                    ->row($this->menuButton())
            )
            ->send();
    }

    public function instructions_apple(): void
    {
        $this->chat->message(config('bot.text.instructions.apple'))
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    public function instructions_adroid(): void
    {
        $this->chat->message(config('bot.text.instructions.android'))
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    public function instructions_windows(): void
    {
        $this->chat->message(config('bot.text.instructions.windows'))
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    public function instructions_mac(): void
    {
        $this->chat->message(config('bot.text.instructions.mac'))
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    public function createCanal(): void
    {
        if ($this->creatOneRandClient()) {
            $this->reply(config('bot.text.clientcreated'));
            $this->myClients();      // показываем список каналов
            $this->instructionRow(); // инструкция по настройке
        } else {
            $this->reply(config('bot.text.clientcreaterror'));
        }
    }

    public function welcome()
    {

        $price = config('vpn.default_price', 12);
        $bonus = config('vpn.entry_bonus', 360); // значение по умолчанию, если ключ отсутствует

        $welcome = config('bot.text.welcome');

        // Заменяем все плейсхолдеры сразу
        $replacements = [
            '{price}' => $price,
            '{bonus}' => $bonus,
        ];
        $welcome = str_replace(array_keys($replacements), array_values($replacements), $welcome);

        $this->chat->message($welcome)->send();
    }

    public function myClients(): void
    {
        $clients = $this->user_clients();

        if (empty($clients)) {
            $this->chat->message('У вас пока нет VPN-каналов.')
                ->keyboard(Keyboard::make()->row($this->menuButton()))
                ->send();
            return;
        }

        $lines = collect($clients)->map(
            fn($c, $idx) => sprintf(
                "🔑 VPN Канал #%d\nСервер: %s\nЛогин: <code>%s</code>\nПароль: <code>%s</code>",
                $idx + 1,
                e($c['s']),
                e($c['n']),
                e($c['p'])
            )
        )->implode("\n\n");

        $this->chat->html($lines)
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    /* ------------------------- 7. Вспомогательные методы ------------------------- */

    /**
     * Возвращает строку кнопок с «Главное меню» для добавления в любую клавиатуру.
     * Использует /start — самый надёжный способ сбросить состояние.
     */
    protected function menuButton(): array
    {
        return [Button::make('🏠 Главное меню')->action('greetExistingAction')];
    }

    /**
     * Публичный action для кнопки «Главное меню».
     */
    public function greetExistingAction(): void
    {
        $this->greetExisting();
    }

    protected function user_id(): int
    {
        return User::getIdByTelegramId($this->chat->chat_id);
    }

    protected function getBalance(): float
    {
        return User::getBalanceByTelegramId($this->chat->chat_id);
    }

    protected function user_clients_count(): int
    {
        return User::getClientsCountByTelegramId($this->chat->chat_id);
    }

    protected function user_clients(): array
    {
        return User::getClientsByTelegramId($this->chat->chat_id);
    }

    protected function creatOneRandClient(): bool
    {
        return User::creatOneClientFromTelegram($this->user_id());
    }

    /* ------------------------- 8. Неиспользуемые методы (закомментированы) ------------------------- */
    // public function hello(): void
    // {
    //     $this->reply('Привет!');
    // }

    // public function myvpn(): void
    // {
    //     try {
    //         $this->myClients();
    //     } catch (\Throwable $e) {
    //         Log::error('myvpn action failed', [
    //             'chat'  => $this->chat->chat_id,
    //             'error' => $e->getMessage(),
    //         ]);
    //         $this->reply('Произошла ошибка. Попробуйте позже.');
    //     }
    // }

    // public function balance(): void
    // {
    //     $user_balance = $this->getBalance();
    //     $this->reply("Ваш баланс: {$user_balance} у.е.");
    // }

    public function instructionRow(): void
    {
        $this->chat->message('📋 Настрой за 1 минуту!')
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make(config('bot.button.instruction'))->action('instructionsGagets'),
                        Button::make(config('bot.button.support'))->url(config('bot.link.support'))
                    ])
                    ->row($this->menuButton())
            )
            ->send();
    }

    // public function youid(): void
    // {
    //     $this->reply('Ваш id: ' . $this->user_id());
    // }

    // public function y(): void
    // {
    //     $this->reply('VPN Клиентов: ' . $this->user_clients_count());
    // }

    /**
     * Шаг 1: Показываем кнопки выбора суммы
     */
    public function addbalance(): void
    {
        Log::info('[YKASSA] Вызов addbalance', ['chat_id' => $this->chat->chat_id]);

        $this->chat->message("💳 Выберите сумму пополнения:")
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make('100 ₽')->action('sendInvoice')->param('amount', 100),
                        Button::make('300 ₽')->action('sendInvoice')->param('amount', 300),
                        Button::make('500 ₽')->action('sendInvoice')->param('amount', 500),
                    ])
                    ->row([
                        Button::make('1000 ₽')->action('sendInvoice')->param('amount', 1000),
                        Button::make('2000 ₽')->action('sendInvoice')->param('amount', 2000),
                        Button::make('5000 ₽')->action('sendInvoice')->param('amount', 5000),
                    ])
                    ->row($this->menuButton())
            )
            ->send();
    }

    /**
     * Шаг 2: Отправка инвойса
     */
    public function sendInvoice(): void
    {
        $amount = (int) ($this->data->get('amount', 100));
        $chatId = $this->chat->chat_id;
        $userId = $this->user_id(); // ваш вспомогательный метод

        Log::info('[YKASSA] sendInvoice вызван', [
            'user_id' => $userId,
            'chat_id' => $chatId,
            'amount' => $amount
        ]);

        $user = User::find($userId);
        if (!$user) {
            Log::error('[YKASSA] Пользователь не найден', ['user_id' => $userId]);
            $this->reply('Ошибка: пользователь не найден.');
            return;
        }

        $payload = json_encode([
            'user_id' => $user->id,
            'amount' => $amount,
            'time' => now()->timestamp
        ]);

        $providerToken = config('telegraph.payments.provider_token');

        try {
            $response = $this->chat
                ->invoice("Пополнение баланса на {$amount} ₽")
                ->description("Сумма к оплате: {$amount} ₽\nБудет зачислено: {$amount} у.е.")
                ->currency('RUB')
                ->addItem('Пополнение баланса', $amount * 100)
                ->payload($payload)
                ->startParameter('pay_' . $user->id)
                ->withData('provider_token', $providerToken)
                ->send();

            Log::info('[YKASSA] Ответ Telegram на sendInvoice', [
                'response' => $response->json(),
                'status' => $response->status()
            ]);

            if ($response->json('ok') === true) {
                Log::info('[YKASSA] Инвойс успешно отправлен', ['message_id' => $response->json('result.message_id')]);
            } else {
                Log::error('[YKASSA] Ошибка отправки инвойса', [
                    'error_code' => $response->json('error_code'),
                    'description' => $response->json('description')
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[YKASSA] Исключение при отправке инвойса', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Шаг 3: Обработка PreCheckoutQuery (подтверждение перед списанием)
     */
    protected function handlePreCheckoutQuery(PreCheckoutQuery $preCheckoutQuery): void
    {
        Log::info('[YKASSA] PreCheckoutQuery получен', ['id' => $preCheckoutQuery->id()]);

        // Берём токен бота из .env (или из config, если он там правильно определён)
        $botToken = env('TELEGRAPH_BOT_TOKEN'); // или config('telegraph.bots.default.token') после настройки

        if (empty($botToken)) {
            Log::error('[YKASSA] Токен бота не найден!');
            throw new \Exception('Ошибка конфигурации бота');
        }

        // Формируем URL и отправляем запрос
        $url = "https://api.telegram.org/bot{$botToken}/answerPreCheckoutQuery";
        $response = Http::post($url, [
            'pre_checkout_query_id' => $preCheckoutQuery->id(),
            'ok' => true,
        ]);

        Log::info('[YKASSA] Ответ на PreCheckoutQuery отправлен', $response->json());

        // Если хотите сохранить payload для последующей обработки
        $payload = json_decode($preCheckoutQuery->invoicePayload(), true);
        if ($payload && isset($payload['user_id'])) {
            cache()->put('payment_' . $preCheckoutQuery->id(), $payload, now()->addMinutes(10));
        }
    }

    /**
     * Шаг 4: Обработка SuccessfulPayment (успешный платёж)
     */
    protected function handleSuccessfulPayment(SuccessfulPayment $successfulPayment): void
    {
        $payload = $successfulPayment->invoicePayload();
        $totalAmount = $successfulPayment->totalAmount();
        $currency = $successfulPayment->currency();
        $providerChargeId = $successfulPayment->providerPaymentChargeId();

        Log::info('[YKASSA] SuccessfulPayment получен', [
            'payload' => $payload,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'provider_charge_id' => $providerChargeId
        ]);

        // Декодируем payload
        $data = json_decode($payload, true);
        if (!$data || !isset($data['user_id']) || !isset($data['amount'])) {
            Log::error('[YKASSA] SuccessfulPayment: неверный payload', ['payload' => $payload]);
            $this->reply('⚠️ Ошибка обработки платежа. Обратитесь в поддержку.');
            return;
        }

        $userId = $data['user_id'];
        $amountRub = $data['amount']; // сумма в рублях

        // Здесь можно начислить баланс, но пока просто логируем
        Log::info('[YKASSA] Успешный платёж пользователя', [
            'user_id' => $userId,
            'amount_rub' => $amountRub,
            'provider_charge_id' => $providerChargeId
        ]);

        try {
            // Создаём транзакцию с информацией о платеже
            Transaction::createTransaction(
                userId: $userId,                // правильно: userId (camelCase)
                type: 'deposit',
                amount: $amountRub,
                subjectType: 'yookassa',        // правильно: subjectType
                subjectId: null,
                comment: "Оплата через ЮKassa, транзакция: {$providerChargeId}",
                isActive: true
            );

            // Проверяем, есть ли у пользователя пригласивший
            $user = User::find($userId);

            if ($user && $user->referrer_id) {

                $bonusPercent = config('vpn.referral_bonus_percent', 10);
                $bonusAmount = round($amountRub * $bonusPercent / 100, 2);

                if ($bonusAmount > 0) {
                    // Создаём транзакцию бонуса для пригласившего
                    Transaction::createTransaction(
                        userId: $user->referrer_id,
                        type: 'deposit',
                        amount: $bonusAmount,
                        subjectType: 'referral_bonus',
                        subjectId: null,
                        comment: "Бонус за приглашение пользователя #{$userId}",
                        isActive: true
                    );

                    Log::info('[Referral] Бонус начислен', [
                        'referrer_id' => $user->referrer_id,
                        'bonus' => $bonusAmount,
                        'from_user' => $userId
                    ]);

                    // Отправляем уведомление пригласившему
                    $this->notifyReferrerAboutBonus($user->referrer_id, $bonusAmount, $user);
                }
            }

            Log::info('[YKASSA] Транзакция создана', [
                'user_id' => $userId,
                'amount' => $amountRub,
                'provider_charge_id' => $providerChargeId
            ]);

            // Отправляем пользователю подтверждение
            $newBalance = $this->getBalance();
            $this->chat->message(
                "✅ *Оплата успешна!*\n\n" .
                    "💰 Зачислено: {$amountRub} у.е.\n" .
                    "💼 Текущий баланс: {$newBalance} у.е.\n"
            )->keyboard(
                Keyboard::make()->row([
                    Button::make('💼 Мой баланс')->action('showbalance')
                ])
            )->send();
        } catch (\Exception $e) {
            Log::error('[YKASSA] Ошибка при создании транзакции', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'amount' => $amountRub,
                'provider_charge_id' => $providerChargeId
            ]);
            $this->reply("⚠️ Платёж прошёл, но ошибка при зачислении. ID: {$providerChargeId}");
        }
    }

    public function ref(): void
    {
        $user = User::find($this->user_id());
        if (!$user) return;

        $botUsername = env('TELEGRAPH_BOT_USERNAME'); // например, 'my_vpn_bot'
        $refLink = "https://t.me/{$botUsername}?start=ref_{$user->id}";

        // Подсчитываем статистику: сколько пригласил, сколько заработал
        $invitedCount = User::where('referrer_id', $user->id)->count();
        $bonusEarned = Transaction::where('user_id', $user->id)
            ->where('subject_type', 'referral_bonus')
            ->sum('amount');

        $text = "🤝 *Ваша реферальная ссылка:*\n`{$refLink}`\n\n";
        $text .= "📊 *Статистика:*\n";
        $text .= "— Приглашено пользователей: *{$invitedCount}*\n";
        $text .= "— Заработано бонусов: *{$bonusEarned} у.е.*\n\n";
        $text .= "За каждого приглашённого вы получаете *" . config('vpn.referral_bonus_percent', 10) . "%* от суммы его пополнений!";

        $this->chat->message($text)
            ->keyboard(Keyboard::make()->row($this->menuButton()))
            ->send();
    }

    // Обработчик для кнопки копирования (опционально)
    public function copyRefLink(): void
    {
        $link = $this->data->get('link');
        // В Telegram нельзя скопировать текст напрямую через кнопку, но можно отправить сообщение со ссылкой
        $this->reply("Ваша реферальная ссылка:\n`{$link}`");
    }

    protected function notifyReferrerAboutNewUser(int $referrerId, User $newUser): void
    {
        $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
        $chat = TelegraphChat::where('chat_id', $newUser->telegram_id) // здесь нужно получить чат реферера, а не нового пользователя
            ->where('telegraph_bot_id', $botId)
            ->first();

        // Но нам нужен чат реферера, а не нового. Исправим:
        $referrer = User::find($referrerId);
        if (!$referrer) return;

        $chat = TelegraphChat::where('chat_id', $referrer->telegram_id)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) return;

        $text = "👋 По вашей реферальной ссылке зарегистрировался новый пользователь!\n\n";
        $text .= "Имя: {$newUser->name}\n";
        $text .= "Когда он пополнит баланс, вы получите бонус.";

        $chat->message($text)->send();
    }

    protected function notifyReferrerAboutBonus(int $referrerId, float $bonus, User $newUser): void
    {
        $botId = env('TELEGRAPH_BOT_NOTIFY_ID');
        $referrer = User::find($referrerId);
        if (!$referrer) return;

        $chat = TelegraphChat::where('chat_id', $referrer->telegram_id)
            ->where('telegraph_bot_id', $botId)
            ->first();

        if (!$chat) return;

        $text = "🎉 Вам начислен бонус *{$bonus} у.е.* за пополнение баланса пользователем {$newUser->name}!\n\n";
        $text .= "Спасибо что приглашаете друзей!";

        $chat->message($text)->send();
    }
}
