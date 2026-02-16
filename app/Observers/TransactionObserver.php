<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\Client;
use App\Services\VpnService;
use Illuminate\Support\Facades\Log;

class TransactionObserver
{
    /**
     * Handle the Transaction "created" event.
     */
    public function created(Transaction $transaction): void
    {
        // 🔥 Обрабатываем ТОЛЬКО депозиты
        // Withdraw обрабатывает cron-команда
        if ($transaction->type !== 'deposit') {
            return;
        }

        $this->checkBalanceAndManageClients($transaction->user_id);
    }

    /**
     * Handle the Transaction "updated" event.
     */
    public function updated(Transaction $transaction): void
    {
        // Если изменили is_active или amount — пересчитываем баланс
        if ($transaction->isDirty('is_active') || $transaction->isDirty('amount')) {
            $this->checkBalanceAndManageClients($transaction->user_id);
        }
    }

    /**
     * Основная логика проверки баланса и управления клиентами
     */
    private function checkBalanceAndManageClients(int $userId): void
    {
        $user = \App\Models\User::find($userId);

        if (!$user) {
            Log::warning("TransactionObserver: User #{$userId} not found");
            return;
        }

        // Считаем баланс
        $balance = $user->balance();

        // Получаем всех клиентов пользователя
        $allClients = Client::where('user_id', $userId)->get();
        $activeClients = $allClients->where('is_active', true);
        $inactiveClients = $allClients->where('is_active', false);

        Log::info("TransactionObserver: User #{$userId} balance check", [
            'balance' => $balance,
            'active_clients' => $activeClients->count(),
            'inactive_clients' => $inactiveClients->count()
        ]);

        // Если баланс >= 0 → активируем всех неактивных клиентов
        if ($balance >= 0) {
            foreach ($inactiveClients as $client) {
                try {
                    $vpn = new VpnService($client->server_name);
                    $vpn->activateClient($client->id);

                    Log::info("Client activated", [
                        'user_id' => $userId,
                        'client_id' => $client->id,
                        'client_name' => $client->name
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to activate client", [
                        'error' => $e->getMessage(),
                        'client_id' => $client->id
                    ]);
                }
            }
        }

        // Если баланс < 0 → деактивируем всех активных клиентов
        else {
            foreach ($activeClients as $client) {
                try {
                    $vpn = new VpnService($client->server_name);
                    $vpn->deactivateClient($client->id);

                    Log::info("Client deactivated", [
                        'user_id' => $userId,
                        'client_id' => $client->id,
                        'client_name' => $client->name
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to deactivate client", [
                        'error' => $e->getMessage(),
                        'client_id' => $client->id
                    ]);
                }
            }
        }
    }
}
