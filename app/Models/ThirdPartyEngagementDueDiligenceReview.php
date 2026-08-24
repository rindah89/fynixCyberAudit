<?php

namespace App\Models;

use App\Enums\ThirdPartyDueDiligenceDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementDueDiligenceReview extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'survey_id', 'cybersecurity_rating', 'privacy_rating', 'resilience_rating', 'compliance_rating', 'financial_rating', 'findings_summary', 'decision', 'conditions', 'rationale', 'next_review_at', 'engagement_snapshot', 'survey_snapshot', 'document_snapshots', 'risk_approval_snapshot', 'engagement_event_fingerprint', 'reviewed_by', 'reviewed_at', 'fingerprint'];

    protected $casts = ['decision' => ThirdPartyDueDiligenceDecision::class, 'next_review_at' => 'date', 'engagement_snapshot' => 'array', 'survey_snapshot' => 'array', 'document_snapshots' => 'array', 'risk_approval_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Third-party due-diligence reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Third-party due-diligence reviews are append-only.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
