<?php

namespace Tests\Feature;

use App\Models\CriteriaVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tài khoản tạm khi chưa có SSO (TempAccountController) và quyền của tài khoản đơn vị chưa gán đơn vị */
class TempAccountTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'keycloak:8080/auth/admin/realms/score';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.temp_accounts.enabled' => true, 'oidc.temp_accounts.client_secret' => 'secret']);
    }

    /** Keycloak giả: $existing = các username/email đã có tài khoản */
    private function fakeKeycloak(array $existing = []): void
    {
        Http::fake([
            'keycloak:8080/auth/realms/score/protocol/openid-connect/token' => Http::response(['access_token' => 'admin-token']),
            self::ADMIN.'/users?*' => fn (Request $r) => Http::response(
                in_array($r->data()['username'] ?? $r->data()['email'] ?? null, $existing, true) ? [['id' => 'x']] : []),
            self::ADMIN.'/users' => Http::response(null, 201, ['Location' => 'http://keycloak:8080/auth/admin/realms/score/users/new-id']),
            self::ADMIN.'/roles/unit' => Http::response(['id' => 'role-unit', 'name' => 'unit']),
            self::ADMIN.'/users/new-id/role-mappings/realm' => Http::response(null, 204),
        ]);
    }

    public function test_new_email_creates_unit_account_with_given_password(): void
    {
        $this->fakeKeycloak();

        $this->postJson('/api/v1/temp-accounts', ['email' => ' Nguyen.A@VNU.edu.vn ', 'password' => 'mat-khau-tu-chon'])->assertNoContent();

        Http::assertSent(fn (Request $r) => $r->url() === 'http://'.self::ADMIN.'/users' && $r->method() === 'POST'
            && $r['username'] === 'nguyen.a@vnu.edu.vn'
            && $r['credentials'][0]['value'] === 'mat-khau-tu-chon'
            && $r['attributes'] === ['tenant' => ['vnu'], 'temp_account' => ['true']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/users/new-id/role-mappings/realm') && $r[0]['name'] === 'unit');
    }

    public function test_existing_account_is_never_touched(): void
    {
        $this->fakeKeycloak(['lanh.dao@vnu.edu.vn']);

        $this->postJson('/api/v1/temp-accounts', ['email' => 'lanh.dao@vnu.edu.vn', 'password' => 'doan-bua'])->assertNoContent();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/admin/'));
    }

    public function test_requires_email_format_and_enabled_switch(): void
    {
        $this->fakeKeycloak();
        foreach (['tuttt', 'ten@vnu', 'a b@vnu.edu.vn'] as $notEmail) {
            $this->postJson('/api/v1/temp-accounts', ['email' => $notEmail, 'password' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/v1/temp-accounts', ['email' => 'a@vnu.edu.vn'])->assertJsonValidationErrors('password');

        config(['oidc.temp_accounts.enabled' => false]);
        $this->postJson('/api/v1/temp-accounts', ['email' => 'a@vnu.edu.vn', 'password' => 'x'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_temp_unit_account_types_unit_name_and_sees_only_own_submissions(): void
    {
        $definition = json_decode(file_get_contents(database_path('data/criteria_v1.json')), true);
        $this->actingAsRole(['unit']);   // tài khoản tạm: role unit, chưa gán đơn vị
        CriteriaVersion::create(['version' => 'v1', 'definition' => $definition, 'is_active' => true]);
        $answers = [];
        foreach ($definition['functions']['basic']['groups'] as $g) {
            foreach ($g['criteria'] as $q) {
                $answers['basic'][$q['id']] = ['yes' => '0', 'note' => 'không', 'evidence_text' => 'không có',
                    'inputs' => collect($q['inputs'] ?? [])->mapWithKeys(fn ($i) => [$i['name'] => 0])->all()];
            }
        }
        $payload = ['functions' => ['basic'], 'weights' => ['basic' => 100], 'answers' => $answers];

        $this->postJson('/api/v1/evaluations', $payload)->assertJsonValidationErrors('organization_name');
        $mine = $this->postJson('/api/v1/evaluations', $payload + ['organization_name' => 'Trường Đại học Công nghệ'])
            ->assertCreated()->assertJsonPath('organization.name', 'Trường Đại học Công nghệ')->json('id');
        $this->getJson('/api/v1/evaluations')->assertOk()->assertJsonCount(1, 'data');

        // Người khác (cũng là tài khoản tạm) nộp cho cùng đơn vị: không thấy bài của nhau
        $this->actingAsRole(['unit']);
        $theirs = $this->postJson('/api/v1/evaluations', $payload + ['organization_name' => 'Trường Đại học Công nghệ - ĐHQGHN'])->assertCreated()->json('id');
        $this->getJson('/api/v1/evaluations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs);
        $this->getJson("/api/v1/evaluations/$mine")->assertForbidden();
        $this->putJson("/api/v1/evaluations/$theirs", $payload)->assertForbidden();   // không sửa được bài

        // Tài khoản đơn vị được cấp (đã gán đơn vị) vẫn thấy mọi bài của đơn vị
        $this->actingAsRole(['unit'], ['unit_name' => 'Trường Đại học Công nghệ']);
        $this->getJson('/api/v1/evaluations')->assertJsonCount(2, 'data');
    }
}
