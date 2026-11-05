<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'evaluation_id', 'question_key', 'original_name', 'object_key', 'mime_type', 'size',
        'analysis_status', 'analysis', 'analyzed_at',
    ];

    protected function casts(): array
    {
        return ['analysis' => 'array', 'analyzed_at' => 'datetime'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }
}
