<?php

namespace Database\Seeders;

use App\Models\CriteriaVersion;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tenant đầu tiên. Mã "vnu" phải khớp attribute/claim "tenant" cấu hình trong Keycloak.
        $tenant = Tenant::updateOrCreate(
            ['code' => 'vnu'],
            ['name' => 'Đại học Quốc gia Hà Nội', 'idp_alias' => 'vnu-sso'],
        );

        // Bộ tiêu chí phiên bản 1 (chỉ tạo nếu chưa có, không ghi đè khi đã chỉnh sửa)
        CriteriaVersion::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'version' => 'v1'],
            [
                'definition' => json_decode(file_get_contents(database_path('data/criteria_v1.json')), true),
                'is_active' => true,
            ],
        );
    }
}
