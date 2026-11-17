<?php

namespace Tests\Feature;

use App\Services\VpnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    /** Подставной сервер: держит список юзеров в памяти */
    private object $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new class (config('vpn.default_server')) extends VpnService {
            public array $users = [];

            protected function shouldExecute(): bool
            {
                return true;
            }

            public function getUsers(): array
            {
                return $this->users;
            }

            public function addUser(string $username, string $password): void
            {
                $this->users[] = $username;
            }

            public function removeUser(string $username): void
            {
                $this->users = array_values(array_diff($this->users, [$username]));
            }
        };

        $this->app->bind(VpnService::class, fn() => $this->server);
    }

    public function test_reports_without_fixing_by_default(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user, ['name' => 'active_missing']);
        $this->server->users = ['stranger'];

        $this->artisan('vpn:reconcile')
            ->expectsOutputToContain('missing: active_missing')
            ->expectsOutputToContain('unknown: stranger')
            ->assertSuccessful();

        $this->assertSame(['stranger'], $this->server->users);
    }

    public function test_fix_adds_missing_and_removes_leaked_but_keeps_unknown(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user, ['name' => 'active_missing']);
        $this->makeClient($user, ['name' => 'blocked_leaked', 'is_active' => false]);
        $this->makeClient($user, ['name' => 'in_sync']);
        $this->server->users = ['blocked_leaked', 'in_sync', 'stranger'];

        $this->artisan('vpn:reconcile', ['--fix' => true])->assertSuccessful();

        sort($this->server->users);
        $this->assertSame(['active_missing', 'in_sync', 'stranger'], $this->server->users);
    }

    public function test_in_sync_server_reports_nothing(): void
    {
        $user = $this->makeConsumer();
        $this->makeClient($user, ['name' => 'in_sync']);
        $this->server->users = ['in_sync'];

        $this->artisan('vpn:reconcile', ['--fix' => true])
            ->doesntExpectOutputToContain('missing')
            ->doesntExpectOutputToContain('leaked')
            ->assertSuccessful();
    }
}
