<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BinderService
{
    /**
     * Возвращает количество клиентов VPN у пользователя
     *
     * @param int|User $user
     * @return int
     */
    public function countVpnClientsForUser($user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Client::where('user_id', $userId)->count();
    }

    /**
     * Возвращает баланс пользователя на основе транзакций
     *
     * @param int|User $user
     * @return float
     */
    public function getUserBalance($user): float
    {
        $userId = $user instanceof User ? $user->id : $user;

        $balance = Transaction::where('user_id', $userId)
            ->where('is_active', true)
            ->selectRaw('SUM(CASE WHEN type = "deposit" THEN amount ELSE -amount END) as balance')
            ->value('balance') ?? 0;

        return (float) $balance;
    }

    /**
     * Возвращает данные для списка пользователей-потребителей с пагинацией
     *
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getConsumerListData(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        // Получаем пользователей с ролью consumer с пагинацией
        $users = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->with('clients')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Добавляем вычисляемые атрибуты
        $users->getCollection()->transform(function ($user) {
            $user->setAttribute('vpn_clients_count', $this->countVpnClientsForUser($user));
            $user->setAttribute('balance', $this->getUserBalance($user));
            return $user;
        });

        return $users;
    }

    /**
     * Проверяет, является ли пользователь consumer
     *
     * @param int|User $user
     * @return bool
     */
    public function isConsumer($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;
        $user = User::with('roles')->find($userId);

        if (!$user) {
            return false;
        }

        return $user->roles->contains(function ($role) {
            return $role->slug === 'consumer';
        });
    }

    /**
     * Возвращает количество активных клиентов VPN у пользователя
     *
     * @param int|User $user
     * @return int
     */
    public function countActiveVpnClientsForUser($user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Client::where('user_id', $userId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Возвращает всех пользователей с ролью consumer
     *
     * @return Collection
     */
    public function getConsumers(): Collection
    {
        return User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->get();
    }
}
