<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TẠM THỜI khi chưa nối VNU-SSO. Trang đăng nhập Keycloak gọi API này ngay trước khi gửi form
 * (docker/keycloak/themes/score/login/resources/js/temp-accounts.js):
 *  - email chưa có tài khoản => tạo tài khoản đơn vị (role unit) với đúng mật khẩu vừa nhập;
 *    các lần sau phải dùng lại mật khẩu đó (Keycloak tự kiểm tra)
 *  - tài khoản đã có (lãnh đạo, quản trị, tài khoản tạm đã tạo) => không làm gì
 * Luôn trả 204 để không lộ email nào đã có tài khoản.
 */
class TempAccountController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $config = config('oidc.temp_accounts');
        abort_unless($config['enabled'], 404);

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', 'email:rfc', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'password' => ['required', 'string', 'max:255'],
        ], ['email.*' => 'Email không đúng định dạng (vd: ten@vnu.edu.vn).']);
        $email = mb_strtolower(trim($data['email']));

        $keycloak = $this->keycloak($config);
        $exists = fn (string $field) => $keycloak->get('users', [$field => $email, 'exact' => 'true', 'max' => 1])->throw()->json();
        if ($exists('username') || $exists('email')) {
            return response()->noContent();
        }

        $created = $keycloak->post('users', [
            'username' => $email,
            'email' => $email,
            'emailVerified' => true,
            'enabled' => true,
            'firstName' => strstr($email, '@', true),
            'attributes' => ['tenant' => [$config['tenant']], 'temp_account' => ['true']],
            'credentials' => [['type' => 'password', 'value' => $data['password'], 'temporary' => false]],
        ]);
        if ($created->status() === 409) {   // vừa được tạo bởi 1 yêu cầu khác cùng lúc
            return response()->noContent();
        }
        $created->throw();

        $userId = basename($created->header('Location'));
        $role = $keycloak->get("roles/{$config['role']}")->throw();
        $keycloak->post("users/$userId/role-mappings/realm", [['id' => $role['id'], 'name' => $role['name']]])->throw();
        Log::info('Tạo tài khoản tạm', ['email' => $email, 'ip' => $request->ip()]);

        return response()->noContent();
    }

    /** Gọi Admin API của Keycloak bằng service account của client score-temp-accounts */
    private function keycloak(array $config): PendingRequest
    {
        $base = rtrim($config['keycloak_url'], '/');
        $token = Http::asForm()->post("$base/realms/{$config['realm']}/protocol/openid-connect/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ])->throw()->json('access_token');

        return Http::withToken($token)->acceptJson()->baseUrl("$base/admin/realms/{$config['realm']}");
    }
}
