<?php

return [
    'servers' => [
        'main' => [ // Имя сервера (может быть любым)
            'host' => '92.51.21.25',
            'port' => 5000,
            'secret_key' => 'azlk2140',
            'protocol' => 'http',
            'status' => 'active', // Статус сервера (может быть 'active', 'inactive', 'maintenance' и т.д.)
        ],
        // Можно добавить другие серверы:
        // 'backup' => [
        //     'host' => '...',
        //     'port' => ...,
        //     'secret_key' => '...',
        //     'protocol' => '...',
        // ],
    ],
    'default_server' => 'main', // Сервер по умолчанию
];