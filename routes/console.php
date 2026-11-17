<?php

use Illuminate\Support\Facades\Schedule;

// Расписание только в production: на DEV крон не нужен, VPN-вызовы там заглушены
if (app()->environment('production')) {
    // 10:30 UTC = 13:30 МСК
    Schedule::command('vpn:daily-charge')->dailyAt('10:30')->withoutOverlapping();

    // Через 15 минут после списания: всё ли на сервере совпадает с базой.
    // Чинит активных без доступа и заблокированных с доступом, остальное — в отчёт админу.
    Schedule::command('vpn:reconcile --fix')->dailyAt('10:45')->withoutOverlapping();
}
