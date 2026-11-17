<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Pricing;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private TelegraphBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        Telegraph::fake();
        $this->bot = TelegraphBot::create(['token' => '123:test-token', 'name' => 'test']);
    }

    private function sendStart(int $telegramId, string $text = '/start', ?string $username = null): void
    {
        $this->postJson("/telegraph/{$this->bot->token}/webhook", [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, 100000),
                'date' => time(),
                'text' => $text,
                'chat' => ['id' => $telegramId, 'type' => 'private', 'first_name' => 'Ivan'],
                'from' => [
                    'id' => $telegramId,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                    'username' => $username,
                ],
            ],
        ])->assertSuccessful();
    }

    public function test_new_user_gets_consumer_role_and_entry_bonus(): void
    {
        $this->sendStart(555001, '/start', 'ivan_new');

        $user = User::where('telegram_id', 555001)->firstOrFail();
        $this->assertTrue(User::consumers()->whereKey($user->id)->exists());
        $this->assertSame(Pricing::entryBonus(), $user->balance());
    }

    public function test_repeated_start_does_not_duplicate_user_or_bonus(): void
    {
        $this->sendStart(555002);
        $this->sendStart(555002);

        $this->assertSame(1, User::where('telegram_id', 555002)->count());
        $user = User::where('telegram_id', 555002)->first();
        $this->assertSame(1, Transaction::where('user_id', $user->id)->where('subject_type', 'entry_bonus')->count());
    }

    public function test_invited_user_gets_invitee_bonus_and_referrer_link(): void
    {
        $referrer = $this->makeConsumer();

        $this->sendStart(555003, "/start ref_{$referrer->id}");

        $user = User::where('telegram_id', 555003)->firstOrFail();
        $this->assertSame($referrer->id, $user->referrer_id);
        $this->assertSame(Pricing::referralInviteeBonus(), $user->balance());
    }

    public function test_taken_username_does_not_break_registration(): void
    {
        $this->makeConsumer(['telegram_username' => 'popular_nick']);

        $this->sendStart(555004, '/start', 'popular_nick');

        $user = User::where('telegram_id', 555004)->firstOrFail();
        $this->assertNull($user->telegram_username);
        $this->assertSame(Pricing::entryBonus(), $user->balance());
    }
}
