<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant = đơn vị đào tạo cấp tài khoản (ĐHQGHN, sau này các trường khác).
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();          // khớp claim "tenant" trong token
            $table->string('name');
            $table->string('idp_alias', 100)->nullable();  // alias Identity Provider trong Keycloak
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Không lưu mật khẩu: danh tính do Keycloak/SSO quản lý, bảng này là hồ sơ nội bộ.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('sso_subject')->nullable();     // claim "sub"
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('full_name')->nullable();
            $table->string('staff_code', 50)->nullable();  // mã cán bộ, khóa để khớp điểm KHCN
            $table->string('unit_name')->nullable();       // tài khoản đơn vị
            $table->jsonb('roles')->default('[]');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sso_subject']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'staff_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
