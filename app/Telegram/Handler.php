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
use Illuminate\Support\Facades\Http;

class Handler extends WebhookHandler
{
    /* ------------------------- 1. Точка входа (/start) ------------------------- */
    public function start(): void
    {
        $from = $this->message->from();

        // Ботам — вход запрещён
        if ($from->isBot()) {
            $this->reply(__('Боты не могут регистрироваться.'));
            return;
        }

        $user = User::where('telegram_id', $from->id())->first();

        if ($user) {
            // Пользователь уже есть в базе
            $this->greetExisting($from);
        } else {
            // Новый пользователь
            $user = $this->registerUser($from);
            $this->greetNewcomer($from);
            $this->awardBonus($user);
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
                    ->row([
                        Button::make(config('bot.button.support'))
                            ->url(config('bot.link.support'))
                    ])
            )
            ->send();
    }

    /* ------------------------- 2. Приветствие нового пользователя ------------------------- */
    private function greetNewcomer(\DefStudio\Telegraph\DTO\User $from): void
    {
        $d = ceil(config('vpn.entry_bonus') / config('vpn.default_price')); // дней бесплатно

        $this->chat->message(
            "👋 Привет, {$from->firstName()}!\n" . config('bot.text.welcome') . "\n\n{$d} дней бесплатно"
        )
            ->keyboard(
                Keyboard::make()
                    ->row([Button::make(config('bot.text.creat'))->action('createCanal')])
                    ->row([
                        Button::make(config('bot.button.instruction'))->action('instructionsGagets'),
                        Button::make(config('bot.button.support'))->url(config('bot.link.support'))
                    ])
            )
            ->send();
    }

    /* ------------------------- 3. Регистрация нового пользователя ------------------------- */
    private function registerUser(\DefStudio\Telegraph\DTO\User $from): User
    {
        $server = config('vpn.default_server');
        $name   = trim($from->firstName() . ' ' . ($from->lastName() ?? ''))
            ?: 'TG_User_' . $from->id();

        $user = User::create([
            'telegram_id'         => $from->id(),
            'telegram_first_name' => $from->firstName(),
            'telegram_last_name'  => $from->lastName(),
            'telegram_username'   => $from->username(),
            'name'                => $name,
            'email'               => ($from->username() ?: 'tg_' . $from->id()) . "@$server",
            'password'            => bcrypt((string)$from->id()),
        ]);

        // Привязываем роль "consumer"
        $role = Role::where('slug', 'consumer')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /* ------------------------- 4. Приветствие существующего пользователя ------------------------- */
    private function greetExisting(\DefStudio\Telegraph\DTO\User $from): void
    {
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

        // 3-я строка: баланс и пополнение
        $rows[] = [
            Button::make('Баланс')->action('showbalance'),
            Button::make('Пополнить')->action('addbalance')->param('uid', $this->message->from()->id()),
        ];

        // Собираем клавиатуру и отправляем
        $keyboard = Keyboard::make();
        foreach ($rows as $row) {
            $keyboard = $keyboard->row($row);
        }

        $this->chat->message(__('Добро пожаловать, :name!', ['name' => $from->firstName()]))
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
            "Ваш баланс: {$user_balance} у.е.\n" .
                "Расход: " . config('vpn.default_price') . " у.е./сутки\n" .
                "Ещё дней: " . ceil($user_balance / config('vpn.default_price'))
        )->send();
    }

    //instructionsGagets
    public function instructionsGagets(): void
    {
        $this->chat->message('Настрой за 1 минуту!')
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make('Apple(iOS)')
                            ->action('instructions_apple'),
                        Button::make('Android')
                            ->action('instructions_adroid'),
                        Button::make('Windows')
                            ->action('instructions_windows')
                    ])
                    ->row([
                        Button::make('Mac')
                            ->action('instructions_mac'),
                        Button::make('Linux')
                            ->url(config('bot.link.support')),
                        Button::make('Роутер')
                            ->url(config('bot.link.support'))
                    ])
            )
            ->send();
    }

    public function instructions_apple(): void
    {
        $this->chat->message(config('bot.text.instructions.apple'))->send();
    }

    public function instructions_adroid(): void
    {
        $this->chat->message(config('bot.text.instructions.android'))->send();
    }

    public function instructions_windows(): void
    {
        $this->chat->message(config('bot.text.instructions.windows'))->send();
    }

    public function instructions_mac(): void
    {
        $this->chat->message(config('bot.text.instructions.mac'))->send();
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
            $this->reply('У вас пока нет VPN-каналов.');
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

        $this->chat->html($lines)->send();
    }

    /* ------------------------- 7. Вспомогательные методы ------------------------- */
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
        $this->chat->message('Настрой за 1 минуту!')
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make(config('bot.button.instruction'))->action('instructionsGagets'),
                        Button::make(config('bot.button.support'))->url(config('bot.link.support'))
                    ])
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
                    ->row([
                        Button::make('🔙 Назад')->action('greetExisting')
                    ])
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

        // Отправляем пользователю подтверждение
        $this->chat->message("✅ Оплата прошла успешно!\n💰 Сумма: {$amountRub} ₽\n🆔 Транзакция: `{$providerChargeId}`")->send();

        // Здесь можно добавить вызов метода начисления средств, например:
        // Transaction::createTransaction($userId, 'deposit', $amountRub, 'Оплата через ЮKassa');
    }
}
