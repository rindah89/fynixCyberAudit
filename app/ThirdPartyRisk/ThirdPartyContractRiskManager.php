<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyContractRiskManager
{
    public function review(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyContractRiskReview
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyContractRiskReview {
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $locked = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id);
            $this->assertCanManage($actor);
            $data = Validator::make($data, self::rules())->validate();
            if (! in_array($locked->status, [ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::RenewalReview], true)) {
                throw ValidationException::withMessages(['engagement' => 'Contract risk review requires an approved engagement or renewal review.']);
            }
            if ($locked->contractRiskReviews()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 retained contract risk reviews.']);
            }
            $assessment = $vendor->latestRiskAssessment()->lockForUpdate()->first();
            $decision = $vendor->latestRiskDecision()->lockForUpdate()->first();
            $risks = $vendor->risks()->orderBy('risks.id')->lockForUpdate()->get();
            $vendor->setRelation('risks', $risks);
            if (! $assessment || ! $decision || $decision->vendor_risk_assessment_id !== $assessment->id
                || ! in_array($decision->decision, [ThirdPartyRiskDecisionType::Approved, ThirdPartyRiskDecisionType::ConditionallyApproved], true)
                || $decision->expires_at?->copy()->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['risk_approval' => 'A current vendor-risk approval is required.']);
            }
            $governance = $vendor->thirdPartyRiskSnapshot($assessment);
            if ($decision->governance_fingerprint !== $governance['fingerprint']
                || ($locked->status === ThirdPartyEngagementStatus::Approved
                    && (data_get($locked->approval_snapshot, 'decision.id') !== $decision->id
                        || data_get($locked->approval_snapshot, 'governance.fingerprint') !== $governance['fingerprint']))) {
                throw ValidationException::withMessages(['risk_approval' => 'The engagement or vendor-risk approval is stale.']);
            }
            abort_if(in_array($actor->id, [$locked->proposed_by, $locked->approved_by, $locked->business_owner_id, $assessment->assessor_id, $decision->decided_by], true), 403, 'Contract risk review must be independently recorded.');
            if ($data['expires_at'] < $data['effective_at']) {
                throw ValidationException::withMessages(['expires_at' => 'Contract expiration must follow its effective date.']);
            }
            $requiredTerms = ['confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance'];
            foreach ($requiredTerms as $field) {
                $data[$field] = (bool) $data[$field];
            }
            if ($data['decision'] === ThirdPartyContractDecision::Approved->value
                && collect($requiredTerms)->contains(fn (string $field): bool => ! $data[$field])) {
                throw ValidationException::withMessages(['decision' => 'Unqualified approval requires every governed risk term to be present.']);
            }
            if ($locked->status === ThirdPartyEngagementStatus::Approved
                && ($data['effective_at'] > $locked->term_start_at->toDateString() || $data['expires_at'] < $locked->term_end_at->toDateString())) {
                throw ValidationException::withMessages(['expires_at' => 'The reviewed contract must cover the retained engagement term.']);
            }
            if ($locked->status === ThirdPartyEngagementStatus::RenewalReview
                && $data['effective_at'] > $locked->term_end_at->toDateString()) {
                throw ValidationException::withMessages(['effective_at' => 'A renewed contract review must begin no later than the current retained term end.']);
            }
            if ($locked->status === ThirdPartyEngagementStatus::RenewalReview) {
                if (blank($data['proposed_term_end_at'] ?? null) || blank($data['proposed_next_review_at'] ?? null)) {
                    throw ValidationException::withMessages(['proposed_term_end_at' => 'A renewal review must retain the proposed extended term and next review date.']);
                }
                if ($data['proposed_term_end_at'] <= $locked->term_end_at->toDateString()
                    || $data['proposed_next_review_at'] < today()->toDateString()
                    || $data['proposed_next_review_at'] > $data['proposed_term_end_at']
                    || $data['expires_at'] < $data['proposed_term_end_at']) {
                    throw ValidationException::withMessages(['proposed_term_end_at' => 'The proposed renewal must extend the term and remain covered by the reviewed contract.']);
                }
            }
            $latestEvent = $locked->events()->reorder()->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $locked->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $engagementSnapshot = $locked->toArray();
            unset($engagementSnapshot['contract_risk_reviews'], $engagementSnapshot['events']);
            $riskSnapshot = ['assessment' => $assessment->toArray(), 'decision' => $decision->toArray(), 'governance' => $governance];
            $at = now()->startOfSecond();
            $canonicalData = [];
            foreach (self::reviewFields() as $field) {
                $canonicalData[$field] = $data[$field] ?? null;
            }
            $payload = $canonicalData + ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->contractRiskReviews()->max('version')) + 1,
                'engagement_snapshot' => $engagementSnapshot, 'risk_approval_snapshot' => $riskSnapshot, 'engagement_event_fingerprint' => $latestEvent->fingerprint,
                'reviewed_by' => $actor->id, 'reviewed_at' => $at->toIso8601String()];

            return $locked->contractRiskReviews()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))])
                ->load('reviewer:id,name');
        }, 3);
    }

    public static function rules(): array
    {
        return ['contract_reference' => 'required|string|max:255', 'agreement_type' => 'required|in:master_service,statement_of_work,data_processing,software_license,other',
            'effective_at' => 'required|date_format:Y-m-d', 'expires_at' => 'required|date_format:Y-m-d', 'proposed_term_end_at' => 'nullable|date_format:Y-m-d', 'proposed_next_review_at' => 'nullable|date_format:Y-m-d',
            'confidentiality_terms' => 'required|boolean', 'data_protection_terms' => 'required|boolean', 'incident_notification_terms' => 'required|boolean',
            'audit_rights' => 'required|boolean', 'subcontractor_controls' => 'required|boolean', 'business_continuity_terms' => 'required|boolean', 'termination_assistance' => 'required|boolean',
            'service_level_summary' => 'required|string|max:30000', 'liability_summary' => 'required|string|max:30000', 'exit_terms_summary' => 'required|string|max:30000',
            'exceptions_summary' => 'nullable|string|max:30000', 'decision' => ['required', Rule::enum(ThirdPartyContractDecision::class)],
            'conditions' => 'required_if:decision,conditionally_approved|nullable|string|max:30000', 'rationale' => 'required|string|max:30000',
            'version' => 'prohibited', 'engagement_snapshot' => 'prohibited', 'risk_approval_snapshot' => 'prohibited', 'reviewed_by' => 'prohibited', 'reviewed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403, 'You cannot review third-party contract risk.');
    }

    /** @return list<string> */
    private static function reviewFields(): array
    {
        return ['contract_reference', 'agreement_type', 'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at', 'confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance', 'service_level_summary', 'liability_summary', 'exit_terms_summary', 'exceptions_summary', 'decision', 'conditions', 'rationale'];
    }
}
