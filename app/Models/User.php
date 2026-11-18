<?php

namespace App\Models;

use App\Services\BinderService;
use App\Services\VpnService;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Platform\Models\Role;
use Orchid\Platform\Models\User as Authenticatable;

class User extends Authenticatable
{
    /** slug роли покупателей VPN. Только они попадают под ежедневное списание. */
    public const ROLE_CONSUMER = 'consumer';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_id',
        'telegram_first_name',
        'telegram_last_name',
        'telegram_username',
        'telegram_verified_at',
        'referrer_id',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'permissions',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'permissions'          => 'array',
        'email_verified_at'    => 'datetime',
        'telegram_verified_at' => 'datetime',
    ];

    /**
     * The attributes for which you can use filters in url.
     *
     * @var array
     */
    protected $allowedFilters = [
        'id'         => Where::class,
        'name'       => Like::class,
        'email'      => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * The attributes for which can use sort in url.
     *
     * @var array
     */
    protected $allowedSorts = [
        'id',
        'name',
        'email',
        'updated_at',
        'created_at',
        'vpn_clients_count',
        'balance',
        'telegram_verified_at',
    ];

    /**
     * Роль покупателя. Создаётся, если её ещё нет: на пустой базе без неё
     * новые юзеры из бота остались бы без роли и не попали бы под списание.
     */
    public static function consumerRole(): Role
    {
        return Role::firstOrCreate(
            ['slug' => self::ROLE_CONSUMER],
            ['name' => 'Consumer', 'permissions' => []]
        );
    }

    /**
     * Только покупатели VPN.
     */
    public function scopeConsumers(Builder $query): Builder
    {
        return $query->whereHas('roles', fn($q) => $q->where('slug', self::ROLE_CONSUMER));
    }

    /**
     * Привязать настоящий telegram_id и пометить его подтверждённым.
     *
     * Чат со старым id удаляется (Telegram о нём не знает), чат с новым
     * создаётся, если его нет: без него крон не сможет писать юзеру.
     * Используют бот (привязка импортированных по username при /start) и
     * карточка покупателя в админке.
     */
    public function linkTelegramId(int $telegramId, ?int $botId): void
    {
        $oldId = $this->telegram_id;

        DB::transaction(function () use ($telegramId, $botId, $oldId) {
            if ($botId && $oldId && (string) $oldId !== (string) $telegramId) {
                TelegraphChat::where('chat_id', (string) $oldId)->where('telegraph_bot_id', $botId)->delete();
            }

            $this->forceFill(['telegram_id' => $telegramId, 'telegram_verified_at' => now()])->save();

            if ($botId && !TelegraphChat::where('chat_id', (string) $telegramId)->where('telegraph_bot_id', $botId)->exists()) {
                // telegraph_bot_id не fillable в модели пакета
                (new TelegraphChat())->forceFill([
                    'chat_id' => (string) $telegramId,
                    'telegraph_bot_id' => $botId,
                    'name' => '[private] ' . ($this->telegram_username ?: $this->name),
                ])->save();
            }
        }, 3);
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referrer_id');
    }

    /**
     * Если баланс уже подтянут подзапросом scopeWithBalance, берём его,
     * иначе считаем отдельным запросом. $value необязателен: старый код
     * вызывал аксессор напрямую без аргументов.
     */
    public function getBalanceAttribute($value = null): float
    {
        return $value !== null ? round((float) $value, 2) : Transaction::balanceFor($this->id);
    }

    public static function getClientsCountByTelegramId($telegramId)
    {
        $user = self::where('telegram_id', $telegramId)->first();

        return $user ? app(BinderService::class)->countVpnClientsForUser($user) : 0;
    }

    public static function getClientsByTelegramId($telegramId)
    {
        $user = self::where('telegram_id', $telegramId)->first();
        abort_if(!$user, 404, 'User not found');

        return $user->clients()->get()
            ->map(fn(Client $client) => [
                's' => $client->server_name,
                'n' => $client->name,
                'p' => $client->password,
            ])
            ->all();
    }

    public static function getIdByTelegramId($telegramId)
    {
        return self::where('telegram_id', $telegramId)->value('id');
    }

    public static function getBalanceByTelegramId($telegramId): float
    {
        $userId = self::where('telegram_id', $telegramId)->value('id');

        return $userId ? Transaction::balanceFor($userId) : 0.0;
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public static function creatOneClientFromTelegram($user_id)
    {
        $vpn = new VpnService();
        $user = self::find($user_id);

        // Если у пользователя нет telegram_username, генерируем на основе id
        $username = $user->telegram_username;
        if (empty($username)) {
            $username = 'c_' . $user->id . '_' . rand(111, 999); // Генерируем уникальное имя
            Log::info('[VPN] Using generated username for client creation', [
                'user_id' => $user_id,
                'generated_username' => $username
            ]);
        }

        $result = $vpn->createClient(
            $username,                 // имя клиента на VPN-сервере
            (string) $user->telegram_id, // telegram_id используется как пароль (by design)
            $user_id,                  // внутренний ID пользователя
            $username,                 // комментарий (можно тоже подставить имя)
            true                       // активировать сразу
        );

        if (!$result) {
            Log::error('[VPN] Failed to create client', [
                'user_id' => $user_id,
                'username' => $username
            ]);
            return false; // Клиент с таким именем уже существует или другая ошибка
        }
        return true;
    }

    public function balance(): float
    {
        return Transaction::balanceFor($this->id);
    }

    // Подзапрос для получения баланса при выборке пользователей (используется в ConsumerListScreen)
    public function scopeWithBalance($query)
    {
        return $query->addSelect([
            'balance' => Transaction::selectRaw(Transaction::BALANCE_SQL)
                ->whereColumn('user_id', 'users.id')
                ->where('is_active', true)
                ->limit(1)
        ]);
    }
}
