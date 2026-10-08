<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tenant đầu tiên. Mã "vnu" phải khớp attribute/claim "tenant" cấu hình trong Keycloak.
        Tenant::updateOrCreate(
            ['code' => 'vnu'],
            ['name' => 'Đại học Quốc gia Hà Nội', 'idp_alias' => 'vnu-sso'],
        );
    }
}
