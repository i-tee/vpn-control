<?php

namespace App\Models;

use App\Services\BinderService;
use App\Services\VpnService;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Platform\Models\User as Authenticatable;

class User extends Authenticatable
{
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
    ];

    public static function getClientsCountByTelegramId($telegramId)
    {
        $user_id =  self::where('telegram_id', $telegramId)->value('id');
        $user = self::find($user_id);
        $bs = new BinderService();
        return $bs->countVpnClientsForUser($user);
    }

    public static function getClientsByTelegramId($telegramId)
    {

        $user = self::where('telegram_id', $telegramId)->first();   // сразу получаем модель
        abort_if(!$user, 404, 'User not found');

        $bs        = new BinderService();
        $list      = $bs->getClientsForUser($user);

        $plain = [];
        foreach ($list as $row) {
            $client = (is_array($row) && isset($row['App\Models\Client']))
                ? $row['App\Models\Client']
                : $row;

            $plain[] = [
                's' => $client['server_name'] ?? $client->server_name,
                'n' => $client['name']        ?? $client->name,
                'p' => $client['password']    ?? $client->password,
            ];
        }

        \Log::debug('telegram_bot: -> clients {clients}', [
            'clients' => $plain
        ]);
        return $plain;
    }

    public static function getIdByTelegramId($telegramId)
    {
        return self::where('telegram_id', $telegramId)->value('id');
    }

    public static function getBalanceByTelegramId($telegramId)
    {
        $user_id = self::where('telegram_id', $telegramId)->value('id');
        return self::find($user_id)->balance();
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

        $result = $vpn->createClient(
            $user->telegram_username,
            $user->telegram_id,
            $user_id,
            $user->telegram_username,
            true
        );

        if (!$result) {
            return false; // Клиент с таким именем уже существует
        }
        return true;

    }

    public function balance()
    {
        return $this->transactions()
            ->where('is_active', true)
            ->selectRaw('SUM(CASE WHEN type = "deposit" THEN amount ELSE -amount END) as balance')
            ->value('balance') ?? 0;
    }
}
