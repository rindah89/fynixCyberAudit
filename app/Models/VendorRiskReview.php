<?php

namespace App\Models;

use App\Enums\ThirdPartyRiskReviewOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class VendorRiskReview extends Model
{
    protected $fillable = ['vendor_id', 'vendor_risk_decision_id', 'reviewed_by', 'outcome', 'summary', 'evidence_reference', 'assessment_version', 'governance_fingerprint', 'next_review_at', 'reviewed_at'];

    protected $casts = ['outcome' => ThirdPartyRiskReviewOutcome::class, 'next_review_at' => 'date', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vendor risk reviews are immutable. Record a new review instead.'));
        static::deleting(fn () => throw new LogicException('Vendor risk reviews are immutable.'));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(VendorRiskDecision::class, 'vendor_risk_decision_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function issue(): HasOne
    {
        return $this->hasOne(VendorRiskIssue::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(VendorRiskReviewEvidence::class);
    }
}
