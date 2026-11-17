<?php

namespace App\Providers;

use App\Services\VpnService;
use Illuminate\Support\ServiceProvider;

class VpnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Не синглтон: у каждого сервера свой экземпляр.
        // app()->makeWith(VpnService::class, ['serverName' => 'x.example.com'])
        $this->app->bind(VpnService::class, function ($app, array $params = []) {
            return new VpnService($params['serverName'] ?? null);
        });
    }

    public function provides(): array
    {
        return [VpnService::class];
    }
}
