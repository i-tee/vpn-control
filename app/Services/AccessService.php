<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Единственное место, где решается, включён ли VPN у юзера.
 *
 * Правило: баланс >= 0 — все каналы активны, баланс < 0 — все заблокированы.
 * Раньше это правило было скопировано в крон и в TransactionObserver.
 */
class AccessService
{
    /**
     * Привести каналы юзера в соответствие с его балансом.
     *
     * @return array{balance: float, activated: Collection<int, Client>, deactivated: Collection<int, Client>}
     */
    public function sync(User|int $user): array
    {
        $userId = $user instanceof User ? $user->id : $user;
        $balance = Transaction::balanceFor($userId);
        $clients = Client::where('user_id', $userId)->get();

        $activated = collect();
        $deactivated = collect();

        if ($balance >= 0) {
            foreach ($clients->where('is_active', false) as $client) {
                if ($this->apply($client, true)) {
                    $activated->push($client);
                }
            }
        } else {
            foreach ($clients->where('is_active', true) as $client) {
                if ($this->apply($client, false)) {
                    $deactivated->push($client);
                }
            }
        }

        if ($activated->isNotEmpty() || $deactivated->isNotEmpty()) {
            Log::info('[Access] Каналы приведены к балансу', [
                'user_id' => $userId,
                'balance' => $balance,
                'activated' => $activated->pluck('id')->all(),
                'deactivated' => $deactivated->pluck('id')->all(),
            ]);
        }

        return compact('balance', 'activated', 'deactivated');
    }

    /**
     * Включить или выключить один канал. Ошибка по одному каналу не мешает
     * остальным: она логируется, канал остаётся в прежнем состоянии.
     */
    private function apply(Client $client, bool $activate): bool
    {
        if (blank($client->name)) {
            Log::warning('[Access] Пропускаем канал без имени', ['client_id' => $client->id]);
            return false;
        }

        try {
            $vpn = new VpnService($client->server_name);
            $activate ? $vpn->activateClient($client->id) : $vpn->deactivateClient($client->id);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Access] Не удалось ' . ($activate ? 'активировать' : 'заблокировать') . ' канал', [
                'client_id' => $client->id,
                'user_id' => $client->user_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
