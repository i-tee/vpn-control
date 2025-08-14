<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BinderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ChargeForVpnClients extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vpn:charge-clients';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge consumers for active VPN clients every hour';

    /**
     * @var BinderService
     */
    protected $binderService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(BinderService $binderService)
    {
        parent::__construct();
        $this->binderService = $binderService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info('Starting hourly VPN client charge process');
        $this->info('Starting hourly VPN client charge process');
        
        try {
            // Получаем всех потребителей
            $consumers = $this->binderService->getConsumers();
            $totalCharged = 0;
            
            foreach ($consumers as $consumer) {
                // Получаем активных клиентов пользователя
                $activeClients = Client::where('user_id', $consumer->id)
                    ->where('is_active', true)
                    ->count();
                
                if ($activeClients > 0) {
                    $this->info("Processing consumer #{$consumer->id} ({$consumer->name}) with {$activeClients} active clients");
                    
                    // Сумма к списанию (1 единица за каждого клиента)
                    $chargeAmount = $activeClients;
                    
                    // Проверяем, достаточно ли средств на балансе
                    $balance = $this->binderService->getUserBalance($consumer);
                    
                    if ($balance >= $chargeAmount) {
                        // Создаем транзакцию списания
                        Transaction::createTransaction(
                            $consumer->id,
                            'withdraw',
                            $chargeAmount,
                            'vpn_service',
                            null,
                            "Charge for {$activeClients} active VPN client(s)"
                        );
                        
                        $this->info("Charged {$chargeAmount} from consumer #{$consumer->id}");
                        Log::info("Charged {$chargeAmount} from consumer #{$consumer->id} for {$activeClients} active clients");
                        $totalCharged += $chargeAmount;
                    } else {
                        $this->warn("Insufficient balance for consumer #{$consumer->id}. Balance: {$balance}, Required: {$chargeAmount}");
                        Log::warning("Insufficient balance for consumer #{$consumer->id}. Balance: {$balance}, Required: {$chargeAmount}");
                    }
                }
            }
            
            $this->info("VPN client charge process completed. Total charged: {$totalCharged}");
            Log::info("VPN client charge process completed. Total charged: {$totalCharged}");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during VPN client charge process: ' . $e->getMessage());
            Log::error('Error during VPN client charge process: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}