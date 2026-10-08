<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CriteriaVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['version', 'definition', 'is_active'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
