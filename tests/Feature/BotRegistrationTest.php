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

    public function test_imported_user_with_wrong_telegram_id_is_relinked_by_username(): void
    {
        // Импорт принял номер телефона за telegram_id
        $imported = $this->makeConsumer(['telegram_id' => 89001234567, 'telegram_username' => 'veronika_x']);
        $client = $this->makeClient($imported);
        $this->deposit($imported, 100, 'entry_bonus');
        (new \DefStudio\Telegraph\Models\TelegraphChat())
            ->forceFill(['chat_id' => '89001234567', 'telegraph_bot_id' => $this->bot->id, 'name' => 'old'])->save();

        $this->sendStart(555101, '/start', 'veronika_x');

        $imported->refresh();
        $this->assertSame(555101, (int) $imported->telegram_id);
        $this->assertNotNull($imported->telegram_verified_at);
        $this->assertSame(1, User::where('telegram_username', 'veronika_x')->count());
        $this->assertSame(0, User::where('telegram_id', 555101)->whereKeyNot($imported->id)->count());
        // Канал и баланс остались при нём, второго бонуса нет
        $this->assertSame($imported->id, $client->fresh()->user_id);
        $this->assertSame(100.0, $imported->balance());
        // Чат со старым id удалён, с настоящим — есть
        $this->assertFalse(\DefStudio\Telegraph\Models\TelegraphChat::where('chat_id', '89001234567')->exists());
        $this->assertTrue(\DefStudio\Telegraph\Models\TelegraphChat::where('chat_id', '555101')->exists());
    }

    public function test_imported_user_without_telegram_id_is_relinked(): void
    {
        $imported = $this->makeConsumer(['telegram_id' => null, 'telegram_username' => 'friend_manual']);

        $this->sendStart(555102, '/start', 'friend_manual');

        $this->assertSame(555102, (int) $imported->fresh()->telegram_id);
        $this->assertSame(1, User::where('telegram_id', 555102)->count());
    }

    public function test_verified_account_cannot_be_taken_over_by_username(): void
    {
        $owner = $this->makeConsumer(['telegram_id' => 555103, 'telegram_username' => 'old_nick']);
        $owner->forceFill(['telegram_verified_at' => now()])->save();

        // Другой человек занял освободившийся username
        $this->sendStart(555104, '/start', 'old_nick');

        $this->assertSame(555103, (int) $owner->fresh()->telegram_id);
        $newcomer = User::where('telegram_id', 555104)->firstOrFail();
        $this->assertNotSame($owner->id, $newcomer->id);
        $this->assertNull($newcomer->telegram_username);
    }

    public function test_start_marks_correctly_imported_user_as_verified(): void
    {
        $imported = $this->makeConsumer(['telegram_id' => 555105, 'telegram_username' => 'correct_one']);

        $this->sendStart(555105, '/start', 'correct_one');

        $this->assertNotNull($imported->fresh()->telegram_verified_at);
        $this->assertSame(1, User::where('telegram_id', 555105)->count());
    }

    public function test_new_users_are_verified_on_registration(): void
    {
        $this->sendStart(555106, '/start', 'brand_new');

        $this->assertNotNull(User::where('telegram_id', 555106)->first()->telegram_verified_at);
    }

    public function test_taken_username_does_not_break_registration(): void
    {
        $owner = $this->makeConsumer(['telegram_username' => 'popular_nick']);
        $owner->forceFill(['telegram_verified_at' => now()])->save();

        $this->sendStart(555004, '/start', 'popular_nick');

        $user = User::where('telegram_id', 555004)->firstOrFail();
        $this->assertNull($user->telegram_username);
        $this->assertSame(Pricing::entryBonus(), $user->balance());
    }
}
