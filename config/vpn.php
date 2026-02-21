<?php

return [
    'servers' => [
        'x.xab.su' => [
            'host' => '5.129.226.167',
            'port' => 5000,
            'secret_key' => env('VPN_SECRET_KEY', 'azlk2140'),
            'protocol' => 'http',
            'status' => 'active',
            'price' => 10, // Добавляем стоимость сервера (10 единиц за клиента в сутки)
        ],
        // Можно добавить другие серверы
        // 'another.server' => [
        //     'host' => '...',
        //     'port' => ...,
        //     'secret_key' => '...',
        //     'protocol' => '...',
        //     'status' => '...',
        //     'price' => 15, // Другая стоимость
        // ],
    ],
    'entry_bonus' => '100', // Вступительный бонус в единицах валюты
    'default_server' => 'x.xab.su',
    'default_price' => 10, // Цена по умолчанию, если сервер не найден в конфиге
    'referral_bonus_percent' => 20, // 20% от суммы пополнения приглашённого
];