<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Pricing;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Orchid\Platform\Models\Role;
use Orchid\Support\Facades\Dashboard;

/**
 * Демо-данные для локальной разработки. Только вымышленные люди и telegram_id
 * из диапазона 9000000xx, к настоящим юзерам отношения не имеют.
 *
 *   docker compose exec app php artisan migrate:fresh --seed
 *
 * Админка: admin@gatekeeper.local / password
 */
class DevSeeder extends Seeder
{
    private const SERVER = 'x.xab.su';

    public function run(): void
    {
        $this->admin();
        $this->devBot();

        // Без обсерверов: не слать письма и не дёргать VPN API на каждую запись
        Model::withoutEvents(fn() => $this->consumers());
    }

    private function admin(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@gatekeeper.local'],
            ['name' => 'admin', 'password' => bcrypt('password')]
        );
        $admin->forceFill(['permissions' => Dashboard::getAllowAllPermission()])->save();
        $this->command?->info('Админ: admin@gatekeeper.local / password');
    }

    /**
     * DEV-бот из TELEGRAPH_BOT_TOKEN локального .env (не боевой бот!).
     */
    private function devBot(): void
    {
        $token = config('gatekeeper.dev_bot_token');
        if (!$token) {
            $this->command?->warn('TELEGRAPH_BOT_TOKEN пуст — DEV-бот не зарегистрирован');
            return;
        }

        $bot = TelegraphBot::firstOrCreate(
            ['token' => $token],
            ['name' => config('gatekeeper.bot_username') ?: 'dev_bot']
        );
        $this->command?->info("DEV-бот: {$bot->name}, id {$bot->id} (TELEGRAPH_BOT_NOTIFY_ID={$bot->id})");
    }

    private function consumers(): void
    {
        $price = Pricing::default();
        $role = User::consumerRole();

        $anna = $this->consumer($role, 1, 'Анна', 'demo_anna');
        $this->client($anna);
        $this->tx($anna, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 30);
        $this->tx($anna, 'deposit', 1000, 'yookassa', daysAgo: 20, externalId: 'yookassa:demo-anna-1');
        $this->dailyCharges($anna, 25, $price);

        // Осталось 3 дня — крон шлёт ему предупреждение
        $boris = $this->consumer($role, 2, 'Борис', 'demo_boris');
        $this->client($boris);
        $this->tx($boris, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 2);
        $this->dailyCharges($boris, 2, $price);

        // Ушёл в минус — канал заблокирован
        $vika = $this->consumer($role, 3, 'Вика', 'demo_vika');
        $this->client($vika, active: false);
        $this->tx($vika, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 7);
        $this->dailyCharges($vika, 6, $price);

        // Глеб пригласил Дину: бонус за активацию + процент с её оплаты
        $gleb = $this->consumer($role, 4, 'Глеб', 'demo_gleb');
        $this->client($gleb);
        $this->tx($gleb, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 40);
        $this->tx($gleb, 'deposit', 500, 'yookassa', daysAgo: 35, externalId: 'yookassa:demo-gleb-1');
        $this->dailyCharges($gleb, 15, $price);

        $dina = $this->consumer($role, 5, 'Дина', 'demo_dina', referrer: $gleb);
        $this->client($dina);
        $this->tx($dina, 'deposit', Pricing::referralInviteeBonus(), 'entry_bonus', daysAgo: 10, comment: 'Вступительный бонус по приглашению');
        $this->tx($dina, 'deposit', 300, 'yookassa', daysAgo: 5, externalId: 'yookassa:demo-dina-1');
        $this->dailyCharges($dina, 9, $price);
        $this->tx($gleb, 'deposit', Pricing::referralActivationBonus(), 'referral_activation', $dina->id, daysAgo: 9);
        $this->tx($gleb, 'deposit', round(300 * Pricing::referralPercent() / 100, 2), 'referral_bonus', $dina->id, daysAgo: 5);

        // Два канала — суточный расход двойной
        $egor = $this->consumer($role, 6, 'Егор', 'demo_egor');
        $this->client($egor);
        $this->client($egor, name: 'demo_egor_tv');
        $this->tx($egor, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 12);
        $this->tx($egor, 'deposit', 500, 'yookassa', daysAgo: 8, externalId: 'yookassa:demo-egor-1');
        $this->dailyCharges($egor, 8, $price * 2);

        // Только зарегистрировалась, канал ещё не создала
        $zhanna = $this->consumer($role, 7, 'Жанна', 'demo_zhanna');
        $this->tx($zhanna, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 0);

        // Без telegram username — логин сгенерирован ботом
        $zoya = $this->consumer($role, 8, 'Зоя', null);
        $this->client($zoya, name: "c_{$zoya->id}_517");
        $this->tx($zoya, 'deposit', Pricing::entryBonus(), 'entry_bonus', daysAgo: 1);
        $this->dailyCharges($zoya, 1, $price);

        $this->command?->info('Демо-покупатели: ' . User::consumers()->count() . ', каналов: ' . Client::count());
    }

    private function consumer(Role $role, int $n, string $name, ?string $username, ?User $referrer = null): User
    {
        $telegramId = 900000000 + $n;
        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $name,
                'telegram_first_name' => $name,
                'telegram_username' => $username,
                'email' => ($username ?: "tg_{$telegramId}") . '@' . self::SERVER,
                'password' => bcrypt((string) $telegramId),
                'referrer_id' => $referrer?->id,
            ]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function client(User $user, bool $active = true, ?string $name = null): void
    {
        Client::firstOrCreate(
            ['name' => $name ?? $user->telegram_username],
            [
                'password' => (string) $user->telegram_id,
                'user_id' => $user->id,
                'server_name' => self::SERVER,
                'telegram_nickname' => $user->telegram_username,
                'is_active' => $active,
            ]
        );
    }

    private function tx(
        User $user,
        string $type,
        float $amount,
        ?string $subjectType,
        ?int $subjectId = null,
        int $daysAgo = 0,
        ?string $externalId = null,
        ?string $comment = null,
    ): void {
        if ($externalId && Transaction::where('external_id', $externalId)->exists()) {
            return;
        }

        $t = Transaction::createTransaction(
            userId: $user->id,
            type: $type,
            amount: $amount,
            subjectType: $subjectType,
            subjectId: $subjectId,
            comment: $comment ?? 'Демо',
            externalId: $externalId,
        );
        $t->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])->saveQuietly();
    }

    private function dailyCharges(User $user, int $days, float $amount): void
    {
        for ($d = $days; $d >= 1; $d--) {
            $this->tx($user, 'withdraw', $amount, 'vpn_service', daysAgo: $d);
        }
    }
}
