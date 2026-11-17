<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\PaymentService;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_is_credited_from_total_amount_in_kopecks(): void
    {
        $user = $this->makeConsumer();

        $result = app(PaymentService::class)->credit($user, 30000, 'charge-1');

        $this->assertSame(PaymentService::RESULT_CREDITED, $result['status']);
        $this->assertSame(300.0, $result['amount']);
        $this->assertSame(300.0, $user->balance());
    }

    public function test_repeated_notification_is_not_credited_twice(): void
    {
        $user = $this->makeConsumer();
        $service = app(PaymentService::class);

        $service->credit($user, 30000, 'charge-1');
        $second = $service->credit($user, 30000, 'charge-1');

        $this->assertSame(PaymentService::RESULT_DUPLICATE, $second['status']);
        $this->assertSame(300.0, $user->balance());
        $this->assertSame(1, Transaction::where('subject_type', 'yookassa')->count());
    }

    public function test_different_payments_are_both_credited(): void
    {
        $user = $this->makeConsumer();
        $service = app(PaymentService::class);

        $service->credit($user, 10000, 'charge-1');
        $service->credit($user, 10000, 'charge-2');

        $this->assertSame(200.0, $user->balance());
    }

    public function test_referrer_gets_percent_of_payment(): void
    {
        $referrer = $this->makeConsumer();
        $friend = $this->makeConsumer(['referrer_id' => $referrer->id]);

        $result = app(PaymentService::class)->credit($friend, 50000, 'charge-1');

        $expected = round(500 * Pricing::referralPercent() / 100, 2);
        $this->assertSame($expected, $result['referral_bonus']);
        $this->assertSame($referrer->id, $result['referrer_id']);
        $this->assertSame($expected, $referrer->balance());
        $this->assertDatabaseHas('transactions', [
            'user_id' => $referrer->id,
            'subject_type' => 'referral_bonus',
            'subject_id' => $friend->id,
        ]);
    }

    public function test_payment_unblocks_clients(): void
    {
        $user = $this->makeConsumer();
        $client = $this->makeClient($user, ['is_active' => false]);
        $this->withdraw($user, 40);

        app(PaymentService::class)->credit($user, 10000, 'charge-1');

        $this->assertTrue($client->fresh()->is_active);
    }
}
