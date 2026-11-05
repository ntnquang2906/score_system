<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kết luận minh chứng do lãnh đạo đặt lại cho 1 tiêu chí của 1 bài (ghi đè kết luận tự động). */
class EvidenceReview extends Model
{
    use BelongsToTenant;

    protected $fillable = ['evaluation_id', 'question_key', 'verdict', 'reason', 'reviewed_by', 'files_signature'];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
