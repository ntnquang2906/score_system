<?php

// Chỉ ghi phần khác mặc định của Laravel (phần còn lại: vendor/laravel/framework/config/auth.php).
// Guard "api": stateless, xác thực Bearer token OIDC (Keycloak), xem AppServiceProvider.
return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'api'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'api' => ['driver' => 'oidc', 'provider' => 'users'],
    ],
];
