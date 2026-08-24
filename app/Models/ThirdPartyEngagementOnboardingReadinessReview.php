<?php

namespace App\Models;

use App\Enums\ThirdPartyOnboardingDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementOnboardingReadinessReview extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'decision', 'conditions', 'summary', 'next_review_at', 'engagement_snapshot', 'requirements_snapshot', 'engagement_event_fingerprint', 'contract_review_fingerprint', 'reviewed_by', 'reviewed_at', 'fingerprint'];

    protected $casts = ['decision' => ThirdPartyOnboardingDecision::class, 'next_review_at' => 'date', 'engagement_snapshot' => 'array', 'requirements_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Onboarding readiness reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Onboarding readiness reviews are append-only.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
