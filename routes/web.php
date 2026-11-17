<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Инструкция по подключению IKEv2. На неё ведёт кнопка «Как подключиться» в боте.
Route::view('/help', 'help.main');
