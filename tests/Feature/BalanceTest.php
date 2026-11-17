<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_is_zero_without_transactions(): void
    {
        $user = $this->makeConsumer();

        $this->assertSame(0.0, $user->balance());
        $this->assertSame(0.0, $user->balance);
    }

    public function test_balance_is_deposits_minus_withdrawals(): void
    {
        $user = $this->makeConsumer();
        $this->deposit($user, 100);
        $this->deposit($user, 50.5);
        $this->withdraw($user, 20);

        $this->assertSame(130.5, $user->balance());
    }

    public function test_cancelled_transactions_are_ignored(): void
    {
        $user = $this->makeConsumer();
        $this->deposit($user, 100);
        $this->withdraw($user, 30)->cancel();

        $this->assertSame(100.0, $user->balance());
    }

    public function test_balances_of_different_users_do_not_mix(): void
    {
        $alice = $this->makeConsumer();
        $bob = $this->makeConsumer();
        $this->deposit($alice, 100);
        $this->withdraw($bob, 40);

        $this->assertSame(100.0, Transaction::balanceFor($alice->id));
        $this->assertSame(-40.0, Transaction::balanceFor($bob->id));
    }

    public function test_with_balance_scope_matches_direct_calculation(): void
    {
        $alice = $this->makeConsumer();
        $bob = $this->makeConsumer();
        $this->deposit($alice, 70);
        $this->withdraw($alice, 20);

        $users = User::query()->select('users.*')->withBalance()->get()->keyBy('id');

        $this->assertSame(50.0, $users[$alice->id]->balance);
        $this->assertSame(0.0, $users[$bob->id]->balance);
    }

    public function test_consumer_role_exists_after_migrations(): void
    {
        $this->assertDatabaseHas('roles', ['slug' => User::ROLE_CONSUMER]);
        $this->assertSame(User::consumerRole()->id, User::consumerRole()->id);
        $this->assertSame(1, \Orchid\Platform\Models\Role::where('slug', User::ROLE_CONSUMER)->count());
    }
}
