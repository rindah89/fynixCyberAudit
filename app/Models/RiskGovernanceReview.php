<?php

namespace App\Models;

use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RiskGovernanceReview extends Model
{
    protected $fillable = ['risk_id', 'risk_governance_profile_id', 'reviewed_by', 'decision', 'summary', 'evidence_reference', 'domain_snapshot', 'inherent_score_snapshot', 'residual_score_snapshot', 'appetite_threshold_snapshot', 'asset_ids_snapshot', 'implementation_ids_snapshot', 'business_service_id_snapshot', 'governance_snapshot', 'governance_fingerprint', 'next_review_at', 'reviewed_at'];

    protected $casts = ['decision' => RiskGovernanceDecision::class, 'domain_snapshot' => RiskDomain::class, 'asset_ids_snapshot' => 'array', 'implementation_ids_snapshot' => 'array', 'governance_snapshot' => 'array', 'next_review_at' => 'date', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Risk governance reviews are immutable. Record a new review instead.'));
        static::deleting(fn () => throw new LogicException('Risk governance reviews are immutable.'));
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RiskGovernanceProfile::class, 'risk_governance_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id_snapshot');
    }

    public function issue(): HasOne
    {
        return $this->hasOne(RiskGovernanceIssue::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RiskGovernanceReviewEvidence::class);
    }
}
