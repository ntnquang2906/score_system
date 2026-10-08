<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Lớp bảo vệ thứ 2 cho multi-tenant: kể cả khi code quên lọc tenant_id, Postgres vẫn chỉ trả
// dữ liệu của tenant hiện tại. Tenant được đặt mỗi request bằng
//   SELECT set_config('app.tenant_id', '<id>', false)
// (xem App\Support\TenantContext). Chưa đặt => current_setting trả NULL => không thấy dòng nào.
return new class extends Migration
{
    private array $tables = ['users', 'organizations', 'criteria_versions', 'evaluations', 'attachments', 'audit_logs'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
