<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BinderService
{

    public function getClientsForUser($user): array
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Client::where('user_id', $userId)->get()->all();
    }

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

        return Transaction::balanceFor((int) $userId);
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
        $users = User::consumers()
            ->with('clients')
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

        return User::consumers()->whereKey($userId)->exists();
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
        return User::consumers()->get();
    }
}
