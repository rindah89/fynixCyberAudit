<?php

namespace App\Models;

use App\Enums\AiGovernanceDecisionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AiGovernanceDecision extends Model
{
    use HasFactory;

    protected $fillable = ['ai_use_case_id', 'ai_risk_assessment_id', 'decided_by', 'decision', 'rationale', 'conditions', 'assessment_version', 'residual_score', 'controls_count', 'risks_count', 'control_ids', 'risk_ids', 'system_snapshot', 'use_case_snapshot', 'governance_fingerprint', 'expires_at', 'decided_at'];

    protected $casts = ['decision' => AiGovernanceDecisionType::class, 'control_ids' => 'array', 'risk_ids' => 'array', 'system_snapshot' => 'array', 'use_case_snapshot' => 'array', 'expires_at' => 'date', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AI governance decisions are immutable. Record a new decision instead.'));
        static::deleting(fn () => throw new LogicException('AI governance decisions are immutable.'));
    }

    public function useCase(): BelongsTo
    {
        return $this->belongsTo(AiUseCase::class, 'ai_use_case_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AiRiskAssessment::class, 'ai_risk_assessment_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
