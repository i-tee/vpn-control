<?php

namespace Tests\Feature;

use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_that_covers_debt_reactivates_clients(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user, ['is_active' => false]);
        $this->withdraw($user, 50);

        $this->deposit($user, 100);

        $this->assertTrue($client->fresh()->is_active);
    }

    public function test_deposit_that_does_not_cover_debt_keeps_clients_blocked(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user, ['is_active' => false]);
        $this->withdraw($user, 100);

        $this->deposit($user, 30);

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_cancelling_a_deposit_blocks_clients(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user);
        $deposit = $this->deposit($user, 100);
        $this->withdraw($user, 20);

        $deposit->cancel();

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_sync_reports_what_changed(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user);
        $this->withdraw($user, 10);

        $result = app(AccessService::class)->sync($user);

        $this->assertSame(-10.0, $result['balance']);
        $this->assertSame([$client->id], $result['deactivated']->pluck('id')->all());
        $this->assertTrue($result['activated']->isEmpty());
    }

    public function test_client_without_name_is_skipped_instead_of_failing(): void
    {
        $user = $this->makeConsumer();
        $broken = $this->makeClient($user, ['name' => '']);
        $good = $this->makeClient($user, ['name' => 'good_one']);
        $this->withdraw($user, 10);

        app(AccessService::class)->sync($user);

        $this->assertTrue($broken->fresh()->is_active);
        $this->assertFalse($good->fresh()->is_active);
    }
}
