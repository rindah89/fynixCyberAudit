<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AiRiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['ai_use_case_id', 'assessor_id', 'version', 'likelihood', 'impact', 'inherent_score', 'residual_likelihood', 'residual_impact', 'residual_score', 'risk_categories', 'assessment_summary', 'mitigation_summary', 'assessed_at'];

    protected $casts = ['risk_categories' => 'array', 'assessed_at' => 'datetime'];

    protected $appends = ['risk_tier'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AI risk assessments are immutable. Create a new version instead.'));
        static::deleting(fn () => throw new LogicException('AI risk assessments are immutable.'));
    }

    public function useCase(): BelongsTo
    {
        return $this->belongsTo(AiUseCase::class, 'ai_use_case_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function getRiskTierAttribute(): string
    {
        return match (true) {
            $this->residual_score <= 4 => 'low', $this->residual_score <= 9 => 'moderate', $this->residual_score <= 16 => 'high', default => 'critical'
        };
    }
}
