<?php

// Xác thực token OIDC do Keycloak phát hành. Khi nối VNU-SSO, Keycloak làm broker
// nên phần cấu hình này giữ nguyên.
return [
    // Có thể khai báo nhiều issuer, phân tách bằng dấu phẩy (vd: IP LAN + domain chính thức)
    'issuers' => array_filter(array_map('trim', explode(',', (string) env('OIDC_ISSUER', '')))),
    'jwks_uri' => env('OIDC_JWKS_URI'),
    'audience' => env('OIDC_AUDIENCE', 'score-api'),
    'tenant_claim' => env('OIDC_TENANT_CLAIM', 'tenant'),
    'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),
    'leeway' => 30,

    // TẠM THỜI khi chưa nối VNU-SSO: đăng nhập bằng email chưa có tài khoản => tự tạo tài khoản đơn vị (role unit)
    // với mật khẩu vừa nhập (xem TempAccountController). Tài khoản đã cấp không bị đụng tới.
    // Khi tích hợp SSO: đặt TEMP_ACCOUNTS_ENABLED=false (xem README, mục "Tài khoản tạm").
    'temp_accounts' => [
        'enabled' => (bool) env('TEMP_ACCOUNTS_ENABLED', false),
        'keycloak_url' => env('KEYCLOAK_INTERNAL_URL', 'http://keycloak:8080/auth'),
        'realm' => env('KEYCLOAK_REALM', 'score'),
        'client_id' => env('TEMP_ACCOUNTS_CLIENT_ID', 'score-temp-accounts'),
        'client_secret' => env('TEMP_ACCOUNTS_CLIENT_SECRET'),
        'tenant' => env('TEMP_ACCOUNTS_TENANT', 'vnu'),
        'role' => 'unit',
    ],
];
