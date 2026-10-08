<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Tenant của request/job hiện tại. Đồng thời đặt biến phiên Postgres "app.tenant_id"
 * để Row-Level Security lọc dữ liệu.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        DB::select("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);
    }

    public function clear(): void
    {
        $this->tenant = null;
        DB::select("SELECT set_config('app.tenant_id', '', false)");
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }
}
