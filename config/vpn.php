<?php

return [
    'servers' => [
        'vpn.xab.su' => [ // Имя сервера (может быть любым)
            'host' => '92.51.21.25',
            'port' => 5000,
            'secret_key' => 'azlk2140',
            'protocol' => 'http',
            'status' => 'active', // Статус сервера (может быть 'active', 'inactive', 'maintenance' и т.д.)
        ],
        // 'main2' => [ // Имя сервера (может быть любым)
        //     'host' => '100.51.21.25',
        //     'port' => 5000,
        //     'secret_key' => 'azlk2140',
        //     'protocol' => 'http',
        //     'status' => 'active', // Статус сервера (может быть 'active', 'inactive', 'maintenance' и т.д.)
        // ],
    ],
    'default_server' => 'vpn.xab.su', // Сервер по умолчанию
];