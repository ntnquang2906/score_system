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
];
