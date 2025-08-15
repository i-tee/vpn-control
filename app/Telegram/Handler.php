<?php

namespace App\Telegram;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use App\Models\Transaction;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Facades\Telegraph;

class Handler extends WebhookHandler
{

    public function start(): void
    {
        $from = $this->message->from();

        if ($from->isBot()) {
            $this->reply(__('Боты не могут регистрироваться.'));
            return;
        }

        $user = User::where('telegram_id', $from->id())->first();

        if ($user) {
            // Уже был
            $this->greetExisting($from);
        } else {
            // Первый раз
            $user = $this->registerUser($from);
            $this->greetNewcomer($from);
            $this->awardBonus($user);
        }
    }

    /**
     * Summary of greetNewcomer
     * @param \DefStudio\Telegraph\DTO\User $from
     * @return void
     * Отправляет приветственное сообщение новому пользователю.
     */
    private function greetNewcomer(\DefStudio\Telegraph\DTO\User $from): void
    {
        $d = ceil(config('vpn.entry_bonus') / config('vpn.default_price')); // Количество дней бесплатного доступа

        $this->chat->message(
            "👋 Привет, {$from->firstName()}!\n" . config('bot.text.welcome') . "\n\n{$d} дней бесплатно"
        )
            ->keyboard(
                Keyboard::make()
                    ->row([Button::make(config('bot.text.creat'))->action('myvpn')])
                    ->row([
                        Button::make(config('bot.button.instruction'))->url(config('bot.link.instruction')),
                        Button::make(config('bot.button.support'))->url(config('bot.link.support'))
                    ])
            )
            ->send();
    }

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

        $role = Role::where('slug', 'consumer')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /**
     * Summary of greetExisting
     * @param \DefStudio\Telegraph\DTO\User $from
     * @return void
     * Отправляет приветственное сообщение существующему пользователю.
     */
    private function greetExisting(\DefStudio\Telegraph\DTO\User $from): void
    {



        $rows = [];

        // первая строка – кнопка «Создать», если нужно
        $firstRow = [];
        if ($this->user_clients_count() >= 1) {
            $firstRow[] = Button::make(config('bot.text.myclients'))->action('myvpn');
        } else {
            $firstRow[] = Button::make(config('bot.text.creat'))->action('myvpn');
        }
        // если массив не пустой – добавляем строку
        if ($firstRow) {
            $rows[] = $firstRow;
        }

        // вторая строка – всегда
        $rows[] = [
            Button::make(config('bot.button.instruction'))->url(config('bot.link.instruction')),
            Button::make(config('bot.button.support'))->url(config('bot.link.support'))
        ];

        // третья строка – всегда
        // $rows[] = [
        //     Button::make('Баланс')->action('checkbalance'),
        //     Button::make('Пополнить')->action('addbalance'),
        // ];

        // строим клавиатуру
        $keyboard = Keyboard::make();
        foreach ($rows as $row) {
            $keyboard = $keyboard->row($row);
        }

        $this->chat->message(__('Добро пожаловать, :name!', ['name' => $from->firstName()]))
            ->keyboard($keyboard)
            ->send();
    }

    /**
     * /
     * @param \App\Models\User $user
     * @return void
     * Начисляет вступительный бонус пользователю.
     * Бонус берется из конфигурации vpn.entry_bonus.
     */
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

    /*******************************************************************************************/

    public function hello()
    {
        $this->reply('Привет!');
    }

    public function myvpn(): void
    {
        $this->reply('Нажата Кнопка myvpn');
    }

    public function checkbalance(): void
    {
        Telegraph::message('Что-то тут с переменными проблемы')->send();
    }

    public function addbalance(): void
    {
        $this->reply("Нажата Кнопка addbalance");
    }

    public function x()
    {
        $user_id = $this->user_id();
        $this->reply("Твой id: {$user_id} ");
    }

    public function y()
    {
        $count_clients = $this->user_clients_count();
        $this->reply("VPN Клиентов: {$count_clients} ");
    }

    public function myClients()
    {
        $clients = $this->user_clients();   // это уже массив вида
        // [['s'=>'x.xab.su','n'=>'pups','p'=>'azlk2140'], …]

        if (empty($clients)) {
            $this->reply('У вас пока нет VPN-каналов.');
            return;
        }

        $lines = collect($clients)->map(
            fn($c, $idx) => sprintf(
                "🔑 VPN Канал #%d\nСервер: %s\nЛогин: %s\nПароль: %s\n",
                $idx + 1,
                $c['s'],
                $c['n'],
                $c['p']
            )
        )->implode("\n");

        $this->reply($lines);
    }

    public function balance()
    {
        $user_balance = $this->getBalance();
        $this->reply("Ваш баланс: {$user_balance} у.е.");
    }

    public function createCanal()
    {
        $r = $this->creatOneRandClient();

        Telegraph::message($r)->send();

    }

    protected function user_id()
    {
        $telegramUser = $this->message->from();
        return User::getIdByTelegramId($telegramUser->id());
    }

    protected function getBalance()
    {
        $telegramUser = $this->message->from();
        return User::getBalanceByTelegramId($telegramUser->id());
    }

    protected function user_clients_count()
    {
        $telegramUser = $this->message->from();
        return User::getClientsCountByTelegramId($telegramUser->id());
    }

    protected function user_clients()
    {

        Log::debug('telegram_bot:' . ' -> Start');

        $telegramUser = $this->message->from();

        // Правильный вариант с использованием массива данных
        Log::debug('telegram_bot /// telegramUser: {user_id}', [
            'user_id' => $telegramUser->id()
        ]);

        $clients = User::getClientsByTelegramId($telegramUser->id());

        Log::debug('telegram_bot: -> COLIO {clients}', [
            'clients' => $clients
        ]);

        return $clients;
    }

    protected function creatOneRandClient()
    {


        $user_id = $this->user_id();
        $v = User::creatOneClientFromTelegram($user_id);

        return $v;

    }
}
