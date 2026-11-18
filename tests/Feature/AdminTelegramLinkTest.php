<?php

namespace Tests\Feature;

use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Facades\Dashboard;
use Tests\TestCase;

/**
 * Ручная привязка настоящего telegram_id в карточке покупателя — для тех
 * импортированных, кого бот не может найти сам (логин канала не совпадает
 * с username в Telegram).
 */
class AdminTelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private TelegraphBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bot = TelegraphBot::create(['token' => '1:admin-test', 'name' => 'test']);
        config(['gatekeeper.notify_bot_id' => $this->bot->id]);

        $this->admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => bcrypt('x')]);
        $this->admin->forceFill(['permissions' => Dashboard::getAllowAllPermission()])->save();
    }

    private function save(User $consumer, array $fields)
    {
        return $this->actingAs($this->admin)->post(
            route('platform.consumers.edit', ['id' => $consumer->id, 'method' => 'save']),
            ['user' => array_merge(['name' => $consumer->name, 'email' => $consumer->email], $fields)]
        );
    }

    public function test_admin_links_real_telegram_id_and_chat_is_created(): void
    {
        $consumer = $this->makeConsumer(['telegram_id' => 89001234567, 'telegram_username' => 'Natalia']);
        (new TelegraphChat())->forceFill(['chat_id' => '89001234567', 'telegraph_bot_id' => $this->bot->id, 'name' => 'old'])->save();

        $this->save($consumer, ['telegram_id' => 777000111, 'telegram_username' => 'natalia_real'])->assertRedirect();

        $consumer->refresh();
        $this->assertSame(777000111, (int) $consumer->telegram_id);
        $this->assertSame('natalia_real', $consumer->telegram_username);
        $this->assertNotNull($consumer->telegram_verified_at);
        $this->assertTrue(TelegraphChat::where('chat_id', '777000111')->where('telegraph_bot_id', $this->bot->id)->exists());
        $this->assertFalse(TelegraphChat::where('chat_id', '89001234567')->exists());
    }

    public function test_telegram_id_of_another_user_is_rejected(): void
    {
        $this->makeConsumer(['telegram_id' => 777000222]);
        $consumer = $this->makeConsumer(['telegram_id' => 89001234568]);

        $this->save($consumer, ['telegram_id' => 777000222])->assertSessionHasErrors('user.telegram_id');

        $this->assertSame(89001234568, (int) $consumer->fresh()->telegram_id);
    }

    public function test_saving_without_changes_keeps_verification_state(): void
    {
        $consumer = $this->makeConsumer(['telegram_id' => 89001234569]);

        $this->save($consumer, ['telegram_id' => 89001234569, 'telegram_username' => $consumer->telegram_username])->assertRedirect();

        $this->assertNull($consumer->fresh()->telegram_verified_at);
    }
}
