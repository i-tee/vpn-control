<?php

namespace Tests\Feature;

use App\Notifications\ClientsBlocked;
use App\Notifications\DailySummary;
use App\Notifications\NewDeposit;
use App\Notifications\NewUserRegistered;
use App\Notifications\VpnClientCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

/**
 * Собираем каждое письмо целиком. Письма уходят из обсерверов и крона внутри
 * try/catch, поэтому сломанное письмо видно только в логе — 18.11.2026 так
 * и случилось с NewDeposit после смены сигнатуры аксессора баланса.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function render($notification): string
    {
        return implode("\n", $notification->toMail(new AnonymousNotifiable())->introLines);
    }

    public function test_new_deposit_mail_shows_new_balance(): void
    {
        $user = $this->makeConsumer();
        $this->withdraw($user, 30);
        $deposit = $this->deposit($user, 1000);

        $text = $this->render(new NewDeposit($deposit));

        $this->assertStringContainsString('1000', $text);
        $this->assertStringContainsString('970', $text);
    }

    public function test_new_user_mail(): void
    {
        $user = $this->makeConsumer(['telegram_username' => 'mail_test']);

        $this->assertStringContainsString('@mail_test', $this->render(new NewUserRegistered($user)));
    }

    public function test_client_created_mail(): void
    {
        $client = $this->makeClient($this->makeConsumer());

        $this->assertStringContainsString($client->name, $this->render(new VpnClientCreated($client)));
    }

    public function test_clients_blocked_mail(): void
    {
        $client = $this->makeClient($this->makeConsumer(), ['is_active' => false]);

        $this->assertStringContainsString($client->name, $this->render(new ClientsBlocked(collect([$client]))));
    }

    public function test_daily_summary_mail(): void
    {
        $mail = (new DailySummary(
            totalConsumers: 5,
            totalClients: 6,
            activeClients: 4,
            inactiveClients: 2,
            totalCharged: 80,
            blockedToday: 1
        ))->toMail(new AnonymousNotifiable());

        $this->assertStringContainsString('80', implode("\n", array_merge($mail->introLines, $mail->outroLines)));
    }
}
