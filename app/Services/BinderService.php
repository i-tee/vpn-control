<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

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
        // Если у пользователя уже загружено количество клиентов, используем его
        if ($user instanceof User && property_exists($user, 'clients_count')) {
            return $user->clients_count;
        }
        
        $userId = $user instanceof User ? $user->id : $user;
        
        return Client::where('user_id', $userId)->count();
    }
    
}