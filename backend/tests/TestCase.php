<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Chốt an toàn: test (có RefreshDatabase xoá sạch bảng) chỉ được chạy trên DB score_test. */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');
        if ($database !== 'score_test') {
            throw new \RuntimeException("Từ chối chạy test trên database \"{$database}\" (chỉ cho phép score_test).");
        }

        return parent::setUpTraits();
    }

    /** Tạo (nếu chưa có) tenant và đặt làm tenant hiện tại. */
    protected function tenant(string $code = 'vnu'): Tenant
    {
        $tenant = Tenant::firstOrCreate(['code' => $code], ['name' => strtoupper($code)]);
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    /** Đăng nhập giả lập 1 user (bỏ qua Keycloak) với các vai trò cho trước. */
    protected function actingAsRole(array $roles, array $attrs = [], string $tenant = 'vnu'): User
    {
        $t = $this->tenant($tenant);
        $user = User::create($attrs + [
            'tenant_id' => $t->id,
            'sso_subject' => fake()->uuid(),
            'username' => fake()->userName(),
            'full_name' => fake()->name(),
            'roles' => $roles,
        ]);
        $this->actingAs($user, 'api');

        return $user;
    }
}
