<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VpnService;
use App\Notifications\ClientsBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;

class ChargeVpnClients extends Command
{
    protected $signature = 'vpn:daily-charge';
    protected $description = 'Daily charge for active VPN clients, deactivate on negative balance, reactivate on positive';

    protected VpnService $vpnService;

    public function __construct(VpnService $vpnService)
    {
        parent::__construct();
        $this->vpnService = $vpnService;
    }

    public function handle()
    {
        $this->info('Starting daily VPN client charge process...');
        Log::info('NOTIKI -- Starting daily VPN client charge process');

        $consumers = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->get();

        $this->info("Found {$consumers->count()} consumers");
        $totalCharged = 0;
        $allBlockedClients = new Collection();

        foreach ($consumers as $consumer) {
            $activeClients = Client::where('user_id', $consumer->id)
                ->where('is_active', true)
                ->get();

            if ($activeClients->isEmpty()) {
                continue;
            }

            $this->line("Processing consumer #{$consumer->id} ({$consumer->name}) with {$activeClients->count()} active clients");

            $totalCharge = 0;
            $serverDetails = [];

            // Расчёт суммы списания по каждому клиенту
            foreach ($activeClients as $client) {
                $serverName = $client->server_name;
                $serversConfig = Config::get('vpn.servers', []);
                $price = $serversConfig[$serverName]['price'] ?? Config::get('vpn.default_price', 1);
                $totalCharge += $price;

                if (!isset($serverDetails[$serverName])) {
                    $serverDetails[$serverName] = [
                        'count' => 1,
                        'price' => $price,
                        'total' => $price,
                    ];
                } else {
                    $serverDetails[$serverName]['count']++;
                    $serverDetails[$serverName]['total'] += $price;
                }
            }

            // Создаём транзакцию списания
            $commentDetails = [];
            foreach ($serverDetails as $serverName => $details) {
                $commentDetails[] = "{$details['count']} active client(s) on {$serverName} ({$details['price']} each)";
            }
            $comment = "Daily charge for " . implode('; ', $commentDetails) . ". Total: {$totalCharge}";

            Transaction::createTransaction(
                $consumer->id,
                'withdraw',
                $totalCharge,
                'vpn_service',
                null,
                $comment
            );

            $this->info("Charged {$totalCharge} from consumer #{$consumer->id}");
            Log::info("NOTIKI -- Charged {$totalCharge} from consumer #{$consumer->id}");
            $totalCharged += $totalCharge;

            // Проверяем баланс после списания
            $balanceAfter = $this->getUserBalance($consumer);
            $this->line("Consumer #{$consumer->id} balance after charge: {$balanceAfter}");

            if ($balanceAfter < 0) {
                // Баланс отрицательный – деактивируем всех активных клиентов
                $this->warn("Negative balance for consumer #{$consumer->id}. Deactivating clients...");
                foreach ($activeClients as $client) {
                    try {
                        // Используем сервис с сервером конкретного клиента
                        $vpn = new VpnService($client->server_name);
                        $vpn->deactivateClient($client->id);
                        $allBlockedClients->push($client);
                        $this->line("Deactivated client #{$client->id} ({$client->name})");
                        Log::warning("NOTIKI -- Deactivated client #{$client->id} for user #{$consumer->id} due to negative balance");
                    } catch (\Exception $e) {
                        $this->error("Failed to deactivate client #{$client->id}: " . $e->getMessage());
                        Log::error("NOTIKI -- Failed to deactivate client #{$client->id}: " . $e->getMessage());
                    }
                }
            } else {
                // Баланс неотрицательный – активируем всех неактивных клиентов (если были)
                $inactiveClients = Client::where('user_id', $consumer->id)
                    ->where('is_active', false)
                    ->get();

                if ($inactiveClients->isNotEmpty()) {
                    $this->info("Positive balance for consumer #{$consumer->id}. Activating inactive clients...");
                    foreach ($inactiveClients as $client) {
                        try {
                            $vpn = new VpnService($client->server_name);
                            $vpn->activateClient($client->id);
                            $this->line("Activated client #{$client->id} ({$client->name})");
                            Log::info("Activated client #{$client->id} for user #{$consumer->id} due to positive balance");
                        } catch (\Exception $e) {
                            $this->error("Failed to activate client #{$client->id}: " . $e->getMessage());
                            Log::error("Failed to activate client #{$client->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        Log::info('NOTIKI -- Before IF to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

        // Отправка уведомления администратору о заблокированных клиентах
        if ($allBlockedClients->isNotEmpty()) {

            Log::info('NOTIKI -- Preparing to send ClientsBlocked', ['count' => $allBlockedClients->count()]);

            $adminEmail = env('ADMIN_EMAIL');
            if ($adminEmail) {
                Notification::route('mail', $adminEmail)
                    ->notify(new ClientsBlocked($allBlockedClients));
                $this->info('Notification about blocked clients sent to admin.');
                Log::info('NOTIKI -- Notification about blocked clients sent to admin.', ['count' => $allBlockedClients->count()]);
            }
        }

        $this->info("Daily charge process completed. Total charged: {$totalCharged}");
        Log::info("NOTIKI -- Daily charge process completed. Total charged: {$totalCharged}");

        return Command::SUCCESS;
    }

    /**
     * Получить баланс пользователя
     */
    private function getUserBalance(User $user): float
    {
        return (float) Transaction::where('user_id', $user->id)
            ->where('is_active', true)
            ->selectRaw('SUM(CASE WHEN type = "deposit" THEN amount ELSE -amount END) as balance')
            ->value('balance') ?? 0;
    }
}
