<?php

namespace App\Models;

use App\Enums\ThirdPartyContractDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyContractRiskReview extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'contract_reference', 'agreement_type', 'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at', 'confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance', 'service_level_summary', 'liability_summary', 'exit_terms_summary', 'exceptions_summary', 'decision', 'conditions', 'rationale', 'engagement_snapshot', 'risk_approval_snapshot', 'engagement_event_fingerprint', 'reviewed_by', 'reviewed_at', 'fingerprint'];

    protected $casts = ['effective_at' => 'date', 'expires_at' => 'date', 'proposed_term_end_at' => 'date', 'proposed_next_review_at' => 'date', 'confidentiality_terms' => 'boolean', 'data_protection_terms' => 'boolean', 'incident_notification_terms' => 'boolean', 'audit_rights' => 'boolean', 'subcontractor_controls' => 'boolean', 'business_continuity_terms' => 'boolean', 'termination_assistance' => 'boolean', 'decision' => ThirdPartyContractDecision::class, 'engagement_snapshot' => 'array', 'risk_approval_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Third-party contract risk reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Third-party contract risk reviews are append-only.'));
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
