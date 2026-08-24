<?php

namespace App\Models;

use App\Enums\PolicyRevisionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PolicyRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_id', 'version', 'status', 'change_summary', 'proposed_effective_date',
        'policy_snapshot', 'submitted_by', 'submitted_at', 'fingerprint',
    ];

    protected $casts = [
        'status' => PolicyRevisionStatus::class,
        'proposed_effective_date' => 'date',
        'policy_snapshot' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if (array_diff(array_keys($revision->getDirty()), ['status']) !== []) {
                throw new LogicException('Policy revision evidence is immutable.');
            }
            if ($revision->getRawOriginal('status') !== PolicyRevisionStatus::PendingReview->value
                || ! in_array($revision->status, [PolicyRevisionStatus::Approved, PolicyRevisionStatus::Rejected], true)) {
                throw new LogicException('Only a pending policy revision can receive a terminal decision.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Policy revision evidence cannot be deleted.'));
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class)->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(PolicyRevisionReview::class);
    }
}
