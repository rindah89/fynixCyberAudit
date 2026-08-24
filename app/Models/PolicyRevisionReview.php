<?php

namespace App\Models;

use App\Enums\PolicyRevisionDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyRevisionReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_revision_id', 'decision', 'review_summary', 'revision_snapshot',
        'reviewed_by', 'reviewed_at', 'fingerprint',
    ];

    protected $casts = [
        'decision' => PolicyRevisionDecision::class,
        'revision_snapshot' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Policy revision reviews are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Policy revision reviews cannot be deleted.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PolicyRevision::class, 'policy_revision_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
