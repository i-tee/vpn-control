<?php

namespace App\Providers;

use App\Services\BinderService;
use Illuminate\Support\ServiceProvider;

class BinderServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(BinderService::class, function ($app) {
            return new BinderService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}