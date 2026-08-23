<?php

namespace App\Models;

use App\Enums\ThirdPartyRiskDecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendorRiskDecision extends Model
{
    protected $fillable = ['vendor_id', 'vendor_risk_assessment_id', 'decided_by', 'decision', 'rationale', 'conditions', 'assessment_version', 'residual_score', 'risk_ids', 'governance_fingerprint', 'expires_at', 'next_review_at', 'decided_at'];

    protected $casts = ['decision' => ThirdPartyRiskDecisionType::class, 'risk_ids' => 'array', 'expires_at' => 'date', 'next_review_at' => 'date', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vendor risk decisions are immutable. Record a new decision instead.'));
        static::deleting(fn () => throw new LogicException('Vendor risk decisions are immutable.'));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(VendorRiskAssessment::class, 'vendor_risk_assessment_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
