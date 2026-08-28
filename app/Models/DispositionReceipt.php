<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositionReceipt extends Model
{
    protected $guarded = [];

    protected $casts = ['eligible_at' => 'immutable_datetime', 'disposed_at' => 'immutable_datetime'];

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class);
    }
}
