<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_charges_daily_price_for_each_active_client(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user);
        $this->makeClient($user, ['name' => 'second_channel']);
        $this->deposit($user, 100);

        $this->artisan('vpn:daily-charge')->assertSuccessful();

        $this->assertSame(100 - 2 * Pricing::default(), $user->balance());
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'withdraw',
            'subject_type' => 'vpn_service',
        ]);
    }

    public function test_second_run_on_the_same_day_does_not_charge_again(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user);
        $this->deposit($user, 100);

        $this->artisan('vpn:daily-charge')->assertSuccessful();
        $this->artisan('vpn:daily-charge')->assertSuccessful();

        $this->assertSame(1, Transaction::where('user_id', $user->id)->where('subject_type', 'vpn_service')->count());
    }

    public function test_force_option_charges_again(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user);
        $this->deposit($user, 100);

        $this->artisan('vpn:daily-charge')->assertSuccessful();
        $this->artisan('vpn:daily-charge', ['--force' => true])->assertSuccessful();

        $this->assertSame(2, Transaction::where('user_id', $user->id)->where('subject_type', 'vpn_service')->count());
    }

    public function test_next_day_charges_again(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user);
        $this->deposit($user, 100);

        Carbon::setTestNow('2026-11-20 10:30:00');
        $this->artisan('vpn:daily-charge')->assertSuccessful();
        Carbon::setTestNow('2026-11-21 10:30:00');
        $this->artisan('vpn:daily-charge')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(100 - 2 * Pricing::default(), $user->balance());
    }

    public function test_negative_balance_blocks_all_clients(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user);
        $this->deposit($user, Pricing::default() / 2);

        $this->artisan('vpn:daily-charge')->assertSuccessful();

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_users_without_active_clients_are_not_charged(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user, ['is_active' => false]);
        // Без обсервера: обычное пополнение само включило бы канал
        Transaction::withoutEvents(fn() => $this->deposit($user, 100));

        $this->artisan('vpn:daily-charge')->assertSuccessful();

        $this->assertSame(100.0, $user->balance());
        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_non_consumers_are_not_charged(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->makeClient($admin, ['name' => 'admin_channel']);

        $this->artisan('vpn:daily-charge')->assertSuccessful();

        $this->assertSame(0, Transaction::where('user_id', $admin->id)->count());
    }

    public function test_referrer_gets_activation_bonus_once(): void
    {
        $referrer = $this->makeConsumer();
        $friend = $this->makeConsumer(['referrer_id' => $referrer->id]);
        $this->makeClient($friend);
        $this->deposit($friend, 200);

        Carbon::setTestNow('2026-11-20 10:30:00');
        $this->artisan('vpn:daily-charge')->assertSuccessful();
        Carbon::setTestNow('2026-11-21 10:30:00');
        $this->artisan('vpn:daily-charge')->assertSuccessful();
        Carbon::setTestNow();

        $bonuses = Transaction::where('subject_type', 'referral_activation')->where('subject_id', $friend->id)->get();
        $this->assertCount(1, $bonuses);
        $this->assertSame($referrer->id, $bonuses->first()->user_id);
        $this->assertSame(Pricing::referralActivationBonus(), $referrer->balance());
    }

    public function test_only_selected_users_are_charged_with_user_ids_option(): void
    {
        $alice = $this->makeConsumer();
        $bob = $this->makeConsumer();
        $this->makeClient($alice);
        $this->makeClient($bob);
        $this->deposit($alice, 100);
        $this->deposit($bob, 100);

        $this->artisan('vpn:daily-charge', ['--user-ids' => (string) $alice->id])->assertSuccessful();

        $this->assertSame(100 - Pricing::default(), $alice->balance());
        $this->assertSame(100.0, $bob->balance());
    }
}
