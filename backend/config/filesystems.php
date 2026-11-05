<?php

// Chỉ ghi phần khác mặc định của Laravel (phần còn lại: vendor/laravel/framework/config/filesystems.php).
return [
    'disks' => [
        // Kho file minh chứng (SeaweedFS hoặc S3 trong nước)
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true, // lỗi kho file phải báo ra, không được im lặng bỏ qua
            'report' => false,
        ],
    ],
];
