<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetentionPolicy extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function legalHolds(): HasMany
    {
        return $this->hasMany(LegalHold::class);
    }
}
