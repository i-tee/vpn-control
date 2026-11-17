<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportVpnSecretsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'secrets');
        file_put_contents($this->file, <<<'SECRETS'
            %any %any : PSK "shared"
            x.xab.su : RSA "privkey.pem"
            ivan_tg : EAP "123456789"
            c_15_482 : EAP "987654321"
            friend : EAP "Manual-Pass1"
            ivan_tg : EAP "123456789"
            SECRETS);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_imports_users_channels_and_starting_balance(): void
    {
        $this->artisan('vpn:import-secrets', ['file' => $this->file, '--balance' => 100])->assertSuccessful();

        $this->assertSame(3, Client::count());
        $this->assertSame(3, User::consumers()->count());

        $ivan = User::where('telegram_id', 123456789)->firstOrFail();
        $this->assertSame('ivan_tg', $ivan->telegram_username);
        $this->assertSame(100.0, $ivan->balance());
        $this->assertTrue($ivan->clients()->first()->is_active);
        $this->assertSame('123456789', $ivan->clients()->first()->password);

        // Сгенерированный логин не становится telegram username
        $generated = User::where('telegram_id', 987654321)->firstOrFail();
        $this->assertNull($generated->telegram_username);

        // Пароль не из цифр — юзер без telegram_id
        $friend = Client::where('name', 'friend')->firstOrFail()->user;
        $this->assertNull($friend->telegram_id);
    }

    public function test_starting_balance_counts_as_entry_bonus(): void
    {
        $this->artisan('vpn:import-secrets', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(3, Transaction::where('subject_type', 'entry_bonus')->count());
    }

    public function test_second_run_does_not_duplicate(): void
    {
        $this->artisan('vpn:import-secrets', ['file' => $this->file])->assertSuccessful();
        $this->artisan('vpn:import-secrets', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(3, Client::count());
        $this->assertSame(3, Transaction::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('vpn:import-secrets', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Client::count());
        $this->assertSame(0, User::count());
    }

    public function test_creates_telegram_chats_for_notifications(): void
    {
        $bot = TelegraphBot::create(['token' => '1:abc', 'name' => 'test']);
        config(['gatekeeper.notify_bot_id' => $bot->id]);

        $this->artisan('vpn:import-secrets', ['file' => $this->file])->assertSuccessful();

        $this->assertTrue(TelegraphChat::where('chat_id', '123456789')->where('telegraph_bot_id', $bot->id)->exists());
        $this->assertSame(2, TelegraphChat::count());
    }

    public function test_import_does_not_trigger_vpn_or_notifications(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        config(['gatekeeper.admin_email' => 'admin@test.local']);

        $this->artisan('vpn:import-secrets', ['file' => $this->file])->assertSuccessful();

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }
}
