<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

if (app()->environment('production')) {
    Schedule::command('vpn:daily-charge')->dailyAt('10:30'); // +3h (MSK)
}
