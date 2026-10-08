<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'organization_id', 'criteria_version_id', 'submitted_by', 'organization_name',
        'functions', 'answers', 'result', 'total_score', 'grade', 'legacy_file', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'functions' => 'array',
            'answers' => 'array',
            'result' => 'array',
            'total_score' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function criteriaVersion(): BelongsTo
    {
        return $this->belongsTo(CriteriaVersion::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
