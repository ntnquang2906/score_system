<?php

namespace App\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Từ Bearer token -> xác định tenant -> tạo/cập nhật hồ sơ user nội bộ.
 * Dùng chung cho tài khoản local Keycloak lẫn tài khoản VNU-SSO (qua Keycloak broker).
 */
class OidcUserResolver
{
    public function __construct(
        private OidcTokenVerifier $verifier,
        private TenantContext $tenants,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (Throwable $e) {
            Log::info('OIDC token bị từ chối', ['reason' => $e->getMessage()]);

            return null;
        }

        $tenantCode = $claims[config('oidc.tenant_claim')] ?? null;
        $tenant = $tenantCode ? Tenant::where('code', $tenantCode)->where('is_active', true)->first() : null;
        if (! $tenant) {
            Log::warning('Token không có tenant hợp lệ', ['sub' => $claims['sub'] ?? null, 'tenant' => $tenantCode]);

            return null;
        }

        $this->tenants->set($tenant);

        $roles = array_values(array_intersect($claims['roles'] ?? [], User::ROLES));

        $user = User::firstOrNew(['sso_subject' => $claims['sub']]);
        $user->fill([
            'username' => $claims['preferred_username'] ?? null,
            'email' => $claims['email'] ?? null,
            'full_name' => $claims['name'] ?? null,
            'staff_code' => $claims['staff_code'] ?? $user->staff_code,
            'unit_name' => $claims['unit_name'] ?? $user->unit_name,
            'roles' => $roles,
        ]);
        if (! $user->last_login_at || $user->last_login_at->lt(now()->subMinutes(5))) {
            $user->last_login_at = now();
        }
        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
