<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ChargeForVpnClientsTest extends Command
{
    protected $signature = 'vpn:test-charge';
    protected $description = 'Test command: Create withdrawal transactions for all VPN clients with server-based pricing';

    public function handle()
    {
        $this->info('Starting test charge process for VPN clients...');
        
        // Получаем всех пользователей с ролью "consumer"
        $consumers = User::whereHas('roles', function ($query) {
            $query->where('slug', 'consumer');
        })->get();
        
        $this->info("Found {$consumers->count()} consumers with role 'consumer'");
        
        $totalCharged = 0;
        
        foreach ($consumers as $consumer) {
            // Получаем всех клиентов пользователя
            $clients = Client::where('user_id', $consumer->id)->get();
            
            if ($clients->count() > 0) {
                $this->info("Consumer #{$consumer->id} ({$consumer->name}) has {$clients->count()} VPN client(s)");
                
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
                            'total' => 0
                        ];
                    }
                    $serverDetails[$serverName]['count']++;
                    $serverDetails[$serverName]['total'] += $price;
                }
                
                // ВСЕГДА создаем транзакцию списания, независимо от баланса
                $balance = $this->getUserBalance($consumer);
                
                // Формируем комментарий с деталями по серверам
                $commentDetails = [];
                foreach ($serverDetails as $serverName => $details) {
                    $commentDetails[] = "{$details['count']} client(s) on {$serverName} ({$details['price']} each)";
                }
                
                $comment = "Test charge for " . implode('; ', $commentDetails) . ". Total: {$totalCharge}";
                
                // Добавляем информацию о недостаточном балансе в комментарий
                if ($balance < $totalCharge) {
                    $comment .= " (Insufficient balance: {$balance})";
                    $this->warn("Insufficient balance for consumer #{$consumer->id}. Balance: {$balance}, Required: {$totalCharge}");
                }
                
                // Создаем транзакцию списания ВСЕГДА, даже если баланс отрицательный
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
            }
        }
        
        if ($totalCharged > 0) {
            $this->info("<fg=green>Successfully created test charges. Total charged: {$totalCharged}</>");
            $this->info('You can check these transactions in the admin panel under Transactions');
        } else {
            $this->info('<fg=yellow>No VPN clients found for consumers. No charges created.</>');
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