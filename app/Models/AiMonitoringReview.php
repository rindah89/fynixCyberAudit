<?php

namespace App\Models;

use App\Enums\AiMonitoringOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AiMonitoringReview extends Model
{
    protected $fillable = ['ai_use_case_id', 'ai_governance_decision_id', 'reviewed_by', 'assessment_version', 'governance_fingerprint', 'outcome', 'performance_summary', 'incidents_count', 'complaints_count', 'evidence_reference', 'next_review_at', 'reviewed_at'];

    protected $casts = ['outcome' => AiMonitoringOutcome::class, 'next_review_at' => 'date', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AI monitoring reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('AI monitoring reviews are immutable.'));
    }

    public function useCase(): BelongsTo
    {
        return $this->belongsTo(AiUseCase::class, 'ai_use_case_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AiGovernanceDecision::class, 'ai_governance_decision_id');
    }

    public function issue(): HasOne
    {
        return $this->hasOne(AiGovernanceIssue::class);
    }
}
