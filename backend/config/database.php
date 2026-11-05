<?php

// Chỉ ghi phần khác mặc định của Laravel (phần còn lại: vendor/laravel/framework/config/database.php).
return [
    'connections' => [
        // Role sở hữu bảng (bỏ qua RLS) - chỉ dùng cho migration và tác vụ quản trị.
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_OWNER_USERNAME', 'root'),
            'password' => env('DB_OWNER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
];
