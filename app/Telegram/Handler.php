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

class Handler extends WebhookHandler
{

    public function start()
    {
        $telegramUser = $this->message->from();

        if ($telegramUser->isBot()) {
            Log::info('Бот пытался зарегистрироваться', ['telegram_id' => $telegramUser->id()]); // INFO вместо WARNING, если это нормальное поведение
            $this->reply(__('К сожалению, боты не могут регистрироваться.'));
            return;
        }

        try {
            $defaultServer = config('vpn.default_server');
            $entry_bonus = config('vpn.entry_bonus');

            $telegramUsername = $telegramUser->username();
            if (empty($telegramUsername)) {
                $telegramUsername = 'tg_' . $telegramUser->id();
            }
            $generatedEmail = $telegramUsername . '@' . $defaultServer;

            $password = (string) $telegramUser->id();

            // Подготавливаем данные для создания/обновления пользователя
            $userData = [
                'telegram_first_name' => $telegramUser->firstName(),
                'telegram_last_name'  => $telegramUser->lastName(),
                'telegram_username'   => $telegramUser->username(),
                'name' => trim($telegramUser->firstName() . ' ' . ($telegramUser->lastName() ?? '')) ?: ('TG_User_' . $telegramUser->id()),
                'email' => $generatedEmail,
                'password' => bcrypt($password),
            ];

            // Создаем или находим пользователя
            $user = User::updateOrCreate(
                ['telegram_id' => $telegramUser->id()],
                $userData
            );


            // Проверим, был ли пользователь создан (новый)
            if ($user->wasRecentlyCreated) {
                // --- Присвоение роли ---
                $consumerRole = Role::where('slug', 'consumer')->first();

                if ($consumerRole) {
                    $user->roles()->attach($consumerRole->id);

                    // --- Начисление бонуса ---
                    try {
                        $bonusTransaction = Transaction::createTransaction(
                            userId: $user->id,
                            type: 'deposit',
                            amount: $entry_bonus,
                            comment: 'Вступительный бонус'
                        );

                        // Отправляем сообщение с уведомлением о бонусе
                        $this->reply(__('Вам начислен вступительный бонус ' . $entry_bonus . ' у.е.!'));
                    } catch (\Exception $transactionException) {
                        // Логируем ошибку начисления
                        Log::error('Ошибка при начислении бонуса', [
                            'user_id' => $user->id,
                            'telegram_id' => $telegramUser->id(),
                            'error' => $transactionException->getMessage()
                        ]);
                        // Пользователь получит общее сообщение об успешной регистрации
                    }
                } else {
                    Log::error('Роль "consumer" не найдена', ['telegram_id' => $telegramUser->id()]);
                }
            } // else - повторный /start, ничего не делаем


            // Отправляем приветственное сообщение (всем)
            //$this->reply(__('Добро пожаловать, :name!', ['name' => $telegramUser->firstName()]));

            $this->chat->message(__('Добро пожаловать, :name!', ['name' => $telegramUser->firstName()]))
                ->keyboard(
                    Keyboard::make()
                        ->row([
                            Button::make('Мой VPN')->action('myvpn'),
                            Button::make('Как настроить')->url('https://gatekeeper.xab.su/help'),
                        ])
                        ->row([
                            Button::make('Баланс')->action('checkbalance'),
                            Button::make('Пополнить')->action('addbalance'),
                        ])
                )
                ->send();
        } catch (\Exception $e) {
            Log::error('Ошибка регистрации из Telegram', [
                'telegram_id' => $telegramUser->id(),
                'error' => $e->getMessage()
            ]);
            $this->reply(__('Произошла ошибка при регистрации. Попробуйте позже.'));
        }
    }

    public function hello()
    {
        $this->reply('Привет!');
    }

    protected function onAction(string $action): void
    {
        match ($action) {
            'myvpn'       => $this->myvpn(),
            'checkbalance' => $this->checkbalance(),
            'addbalance'  => $this->addbalance(),
            default       => $this->reply('Неизвестное действие'),
        };
    }

    public function menu(): void
    {
        $this->chat->message('Выберите действие')
            ->keyboard(
                Keyboard::make()
                    ->row([
                        Button::make('myvpn')->action('myvpn'),
                        Button::make('checkbalance')->action('checkbalance'),
                    ])
                    ->row([
                        Button::make('Кнопка 3')->url('https://gatekeeper.xab.su/help'),
                        Button::make('addbalance')->action('addbalance'),
                    ])
            )
            ->send();
    }

    public function myvpn(): void
    {
        $this->reply('Нажата Кнопка myvpn');
    }

    public function checkbalance(): void
    {
        $this->reply('Нажата Кнопка checkbalance');
    }

    public function addbalance(): void
    {

        $telegramUser = $this->message->from();
        //$user_id = User::getIdByTelegramId($telegramUser->id());
        $user_id = $telegramUser->id();

        $this->reply("Нажата Кнопка {$user_id} addbalance");
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

    public function yl()
    {
        $clients = $this->user_clients();   // это уже массив вида
        // [['s'=>'x.xab.su','n'=>'pups','p'=>'azlk2140'], …]

        if (empty($clients)) {
            $this->reply('У вас пока нет VPN-клиентов.');
            return;
        }

        $lines = collect($clients)->map(
            fn($c, $idx) => sprintf(
                "🔑 VPN Клиент #%d\nСервер: %s\nЛогин: %s\nПароль: %s\n",
                $idx + 1,
                $c['s'],
                $c['n'],
                $c['p']
            )
        )->implode("\n");

        $this->reply($lines);
    }

    public function z()
    {
        $user_balance = $this->user_balance();
        $this->reply("Твой баланс: {$user_balance} ");
    }

    protected function user_id()
    {
        $telegramUser = $this->message->from();
        return User::getIdByTelegramId($telegramUser->id());
    }

    protected function user_balance()
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
}
