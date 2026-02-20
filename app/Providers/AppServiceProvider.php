<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Client;
use App\Observers\UserObserver;
use App\Observers\ClientObserver;
use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;
use App\Observers\TransactionObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        User::observe(UserObserver::class);
        Transaction::observe(TransactionObserver::class);
        Client::observe(ClientObserver::class);
    }
}
