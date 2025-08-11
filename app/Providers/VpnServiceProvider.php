<?php

namespace App\Providers;

use App\Services\VpnService;
use Illuminate\Support\ServiceProvider;

class VpnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VpnService::class, function () {
            return new VpnService();
        });
    }

    public function provides(): array
    {
        return [VpnService::class];
    }
}