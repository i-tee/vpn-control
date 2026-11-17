<?php

namespace Tests;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Тесты не должны ходить ни в Telegram, ни на VPN-сервер
        Http::preventStrayRequests();

        // Уведомления в Telegram отключены: нет бота, нет и попыток отправки
        config([
            'gatekeeper.notify_bot_id' => null,
            'gatekeeper.admin_chat_id' => null,
            'gatekeeper.admin_email' => null,
            'telegram.proxy_enabled' => false,
        ]);
    }

    protected function makeConsumer(array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create(array_merge([
            'name' => "Consumer {$seq}",
            'email' => "consumer{$seq}@test.local",
            'password' => bcrypt('secret'),
            'telegram_id' => 100000 + $seq,
            'telegram_username' => "consumer_{$seq}",
        ], $attributes));

        $user->roles()->attach(User::consumerRole()->id);

        return $user;
    }

    protected function makeClient(User $user, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => $user->telegram_username ?? "c_{$user->id}",
            'password' => (string) $user->telegram_id,
            'user_id' => $user->id,
            'server_name' => config('vpn.default_server'),
            'is_active' => true,
        ], $attributes));
    }

    protected function deposit(User $user, float $amount, ?string $subjectType = null): Transaction
    {
        return Transaction::createTransaction($user->id, 'deposit', $amount, $subjectType);
    }

    protected function withdraw(User $user, float $amount, ?string $subjectType = null): Transaction
    {
        return Transaction::createTransaction($user->id, 'withdraw', $amount, $subjectType);
    }
}
