<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiJob extends Model
{
    protected $fillable = [
        'type',
        'subject_type',
        'subject_id',
        'status',
        'total',
        'processed',
        'failed',
        'result_path',
        'error',
        'meta',
        'created_by',
        'cancelled_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled' || $this->cancelled_at !== null;
    }
}
