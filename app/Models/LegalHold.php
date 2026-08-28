<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalHold extends Model
{
    protected $guarded = [];

    protected $casts = ['placed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class);
    }
}
