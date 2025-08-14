<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VpnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ChargeForVpnClientsTest extends Command
{
    protected $signature = 'vpn:test-charge';
    protected $description = 'Test command: Create withdrawal transactions for all ACTIVE VPN clients with server-based pricing and manage client activation/deactivation based on balance';

    public function handle()
    {
        $this->info('Starting test charge process for ACTIVE VPN clients...');
        
        // Получаем всех пользователей с ролью "consumer"
        $consumers = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->get();
        
        $this->info("Found {$consumers->count()} consumers with role 'consumer'");
        
        $totalCharged = 0;
        
        foreach ($consumers as $consumer) {
            // Получаем ТОЛЬКО АКТИВНЫХ клиентов пользователя
            $clients = Client::where('user_id', $consumer->id)
                ->where('is_active', true)
                ->get();
            
            if ($clients->count() > 0) {
                $this->info("Consumer #{$consumer->id} ({$consumer->name}) has {$clients->count()} ACTIVE VPN client(s)");
                
                $totalCharge = 0;
                $serverDetails = [];
                
                // Рассчитываем сумму списания с учетом стоимости серверов
                foreach ($clients as $client) {
                    $serverName = $client->server_name;
                    $serversConfig = Config::get('vpn.servers', []);
                    
                    // Получаем цену сервера из конфига, если сервер не найден - используем цену по умолчанию
                    $price = isset($serversConfig[$serverName]['price']) 
                        ? $serversConfig[$serverName]['price'] 
                        : Config::get('vpn.default_price', 1);
                    
                    $totalCharge += $price;
                    
                    // Собираем информацию для комментария
                    if (!isset($serverDetails[$serverName])) {
                        $serverDetails[$serverName] = [
                            'count' => 0,
                            'price' => $price,
                            'total' => $price
                        ];
                    } else {
                        $serverDetails[$serverName]['count']++;
                        $serverDetails[$serverName]['total'] += $price;
                    }
                }
                
                // ВСЕГДА создаем транзакцию списания, независимо от баланса
                $balanceBeforeCharge = $this->getUserBalance($consumer);
                
                // Формируем комментарий с деталями по серверам
                $commentDetails = [];
                foreach ($serverDetails as $serverName => $details) {
                    $commentDetails[] = "{$details['count']} ACTIVE client(s) on {$serverName} ({$details['price']} each)";
                }
                
                $comment = "Test charge for " . implode('; ', $commentDetails) . ". Total: {$totalCharge}";
                
                // Создаем транзакцию списания
                Transaction::createTransaction(
                    $consumer->id,
                    'withdraw',
                    $totalCharge,
                    'vpn_service',
                    null,
                    $comment
                );
                
                $this->info("Charged {$totalCharge} from consumer #{$consumer->id}");
                $totalCharged += $totalCharge;
            } else {
                $this->line("Consumer #{$consumer->id} ({$consumer->name}) has no ACTIVE VPN clients");
            }
            
            // Проверяем баланс ПОСЛЕ списания
            $balanceAfterCharge = $this->getUserBalance($consumer);
            $this->info("Consumer #{$consumer->id} balance after charge: {$balanceAfterCharge}");
            
            // Если баланс отрицательный, деактивируем всех активных клиентов
            if ($balanceAfterCharge < 0) {
                $this->warn("Negative balance for consumer #{$consumer->id} ({$balanceAfterCharge}). Deactivating all active clients.");
                
                // Получаем всех активных клиентов пользователя
                $activeClients = Client::where('user_id', $consumer->id)
                    ->where('is_active', true)
                    ->get();
                
                foreach ($activeClients as $client) {
                    try {
                        // Инициализируем VpnService с сервером клиента
                        $vpn = new VpnService($client->server_name);
                        
                        // Деактивируем клиента через сервис
                        $vpn->deactivateClient($client->id);
                        
                        $this->info("Deactivated client '{$client->name}' for consumer #{$consumer->id}");
                    } catch (\Exception $e) {
                        $this->error("Failed to deactivate client '{$client->name}' for consumer #{$consumer->id}: " . $e->getMessage());
                    }
                }
            } 
            // Если баланс положительный, активируем всех неактивных клиентов
            else if ($balanceAfterCharge >= 0) {
                $this->info("Positive balance for consumer #{$consumer->id} ({$balanceAfterCharge}). Activating all inactive clients.");
                
                // Получаем всех неактивных клиентов пользователя
                $inactiveClients = Client::where('user_id', $consumer->id)
                    ->where('is_active', false)
                    ->get();
                
                foreach ($inactiveClients as $client) {
                    try {
                        // Инициализируем VpnService с сервером клиента
                        $vpn = new VpnService($client->server_name);
                        
                        // Активируем клиента через сервис
                        $vpn->activateClient($client->id);
                        
                        $this->info("Activated client '{$client->name}' for consumer #{$consumer->id}");
                    } catch (\Exception $e) {
                        $this->error("Failed to activate client '{$client->name}' for consumer #{$consumer->id}: " . $e->getMessage());
                    }
                }
            }
        }
        
        if ($totalCharged > 0) {
            $this->info("<fg=green>Successfully created test charges. Total charged: {$totalCharged}</>");
            $this->info('You can check these transactions in the admin panel under Transactions');
        } else {
            $this->info('<fg=yellow>No ACTIVE VPN clients found for consumers. No charges created.</>');
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Возвращает баланс пользователя
     *
     * @param User $user
     * @return float
     */
    private function getUserBalance(User $user): float
    {
        $balance = Transaction::where('user_id', $user->id)
            ->where('is_active', true)
            ->selectRaw('SUM(CASE WHEN type = "deposit" THEN amount ELSE -amount END) as balance')
            ->value('balance') ?? 0;
            
        return (float) $balance;
    }
}