<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'normalized_key'];

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }
}
