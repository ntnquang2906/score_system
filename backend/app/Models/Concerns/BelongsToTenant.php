<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tự gán tenant_id khi tạo và tự lọc theo tenant khi truy vấn.
 * RLS ở Postgres là lớp bảo vệ thứ 2 phía sau scope này.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (! $model->tenant_id) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app(TenantContext::class)->id();
            if ($tenantId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
