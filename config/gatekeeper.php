<?php

/*
|--------------------------------------------------------------------------
| Gatekeeper — проектные настройки
|--------------------------------------------------------------------------
|
| Единственное место, где читаются проектные переменные окружения.
| В коде используется только config('gatekeeper.*'), никаких env():
| после `php artisan config:cache` env() вне config/ возвращает null.
|
*/

return [

    // Куда слать письма DailySummary / ClientsBlocked / NewDeposit / NewUserRegistered
    'admin_email' => env('ADMIN_EMAIL'),

    // telegram_id админа — получатель сводки крона в Telegram
    'admin_chat_id' => env('ADMIN_CHAT_ID'),

    // id записи в таблице telegraph_bots, через которую шлём уведомления
    'notify_bot_id' => env('TELEGRAPH_BOT_NOTIFY_ID'),

    // username бота без @, для реферальных ссылок t.me/<username>?start=ref_N
    'bot_username' => env('TELEGRAPH_BOT_USERNAME'),

];
