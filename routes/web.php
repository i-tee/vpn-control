<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});



/*

use App\Http\Controllers\VpnTestController;

// Тестовые маршруты
Route::prefix('vpn-test')->group(function () {
    // Показать форму и список
    Route::get('/', function () {
        return view('vpn.test', [
            'action' => 'Тестирование VPN API',
            'instructions' => [
                '1. Получить список пользователей',
                '2. Добавить нового пользователя',
                '3. Удалить существующего пользователя'
            ]
        ]);
    });

    // Получить список пользователей
    Route::get('/users', [VpnTestController::class, 'listUsers'])->name('vpn.users');

    // Добавить пользователя
    Route::post('/add', [VpnTestController::class, 'addUser'])->name('vpn.add');
    
    // Удалить пользователя
    Route::post('/remove', [VpnTestController::class, 'removeUser'])->name('vpn.remove');
});

*/