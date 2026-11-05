<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** Ghi nhật ký cho 1 bài đánh giá; $request (nếu có) cho người thực hiện, IP, trình duyệt */
    public static function record(string $action, Evaluation $evaluation, ?array $changes, ?Request $request = null): self
    {
        return self::create([
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'subject_type' => 'evaluation',
            'subject_id' => $evaluation->id,
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
