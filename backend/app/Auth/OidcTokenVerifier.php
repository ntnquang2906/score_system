<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Kiểm tra access token (JWT) do Keycloak phát hành: chữ ký (JWKS), hạn, issuer, audience.
 */
class OidcTokenVerifier
{
    private const CACHE_KEY = 'oidc:jwks';

    public function verify(string $token): array
    {
        JWT::$leeway = config('oidc.leeway');

        try {
            $claims = (array) JWT::decode($token, $this->keys());
        } catch (UnexpectedValueException $e) {
            // Keycloak có thể vừa xoay khoá ký: tải lại JWKS một lần rồi thử lại
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            $claims = (array) JWT::decode($token, $this->keys(refresh: true));
        }

        if (! in_array($claims['iss'] ?? null, config('oidc.issuers'), true)) {
            throw new UnexpectedValueException('Issuer không hợp lệ: '.($claims['iss'] ?? '(trống)'));
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array(config('oidc.audience'), $audience, true)) {
            throw new UnexpectedValueException('Token không dành cho API này (aud).');
        }

        return json_decode(json_encode($claims), true);
    }

    private function keys(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $jwks = Cache::remember(self::CACHE_KEY, config('oidc.jwks_cache_ttl'), function () {
            return Http::timeout(5)->get(config('oidc.jwks_uri'))->throw()->json();
        });

        // Chỉ giữ khoá dùng để ký (Keycloak còn trả về khoá mã hoá RSA-OAEP)
        $jwks['keys'] = array_values(array_filter(
            $jwks['keys'] ?? [],
            fn ($key) => ($key['use'] ?? 'sig') === 'sig'
        ));

        return JWK::parseKeySet($jwks);
    }
}
