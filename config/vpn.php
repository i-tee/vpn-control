<?php

return [
    'servers' => [
        'x.xab.su' => [
            'host' => '5.129.226.167',
            'port' => 5000,
            'secret_key' => 'azlk2140',
            'protocol' => 'http',
            'status' => 'active',
            'price' => 12, // Добавляем стоимость сервера (10 единиц за клиента в сутки)
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
    'default_server' => 'x.xab.su',
    'default_price' => 9, // Цена по умолчанию, если сервер не найден в конфиге
];