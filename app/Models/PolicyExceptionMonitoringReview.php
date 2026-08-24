<?php

namespace App\Models;

use App\Enums\PolicyExceptionMonitoringOutcome;
use Database\Factories\PolicyExceptionMonitoringReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PolicyExceptionMonitoringReview extends Model
{
    /** @use HasFactory<PolicyExceptionMonitoringReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'policy_exception_id', 'version', 'outcome', 'review_summary', 'control_effectiveness',
        'evidence_reference', 'exception_snapshot', 'reviewed_by', 'reviewed_at', 'next_review_at', 'fingerprint',
    ];

    protected $casts = [
        'outcome' => PolicyExceptionMonitoringOutcome::class,
        'exception_snapshot' => 'array',
        'reviewed_at' => 'datetime',
        'next_review_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Policy exception monitoring reviews are append-only.'));
        static::deleting(fn () => throw new \LogicException('Policy exception monitoring reviews cannot be deleted.'));
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(PolicyException::class, 'policy_exception_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function issue(): HasOne
    {
        return $this->hasOne(PolicyExceptionMonitoringIssue::class);
    }
}
