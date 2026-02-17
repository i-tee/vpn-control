<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

if (app()->environment('production')) {
    Schedule::command('vpn:daily-charge')->dailyAt('12:23'); // +3h (15:23 MSK)
}
