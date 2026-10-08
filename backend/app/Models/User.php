<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\UnitName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToTenant, Notifiable;

    public const ROLES = ['admin', 'editor', 'viewer', 'unit', 'researcher'];

    protected $fillable = [
        'tenant_id', 'sso_subject', 'username', 'email', 'full_name',
        'staff_code', 'unit_name', 'organization_id', 'roles', 'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return count(array_intersect($roles, $this->roles ?? [])) > 0;
    }

    /** Được xem toàn bộ bài đánh giá của tenant (lãnh đạo/quản trị). */
    public function canSeeAllEvaluations(): bool
    {
        return $this->hasAnyRole('admin', 'editor', 'viewer');
    }

    /** Khoá đơn vị của tài khoản đơn vị (null nếu không phải tài khoản đơn vị). */
    public function unitKey(): ?string
    {
        return $this->hasAnyRole('unit') && $this->unit_name ? UnitName::key($this->unit_name) : null;
    }
}
