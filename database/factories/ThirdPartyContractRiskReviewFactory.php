<?php

namespace Database\Factories;

use App\Enums\RiskDomain;
use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Models\Risk;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\User;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyContractRiskReviewFactory extends Factory
{
    protected $model = ThirdPartyContractRiskReview::class;

    public function definition(): array
    {
        return ['third_party_engagement_id' => fn (): int => $this->approvedEngagement()->id, 'version' => 1,
            'contract_reference' => fake()->unique()->bothify('MSA-####-????'), 'agreement_type' => 'master_service',
            'effective_at' => today(), 'expires_at' => today()->addYear(), 'proposed_term_end_at' => null, 'proposed_next_review_at' => null, 'confidentiality_terms' => true, 'data_protection_terms' => true,
            'incident_notification_terms' => true, 'audit_rights' => true, 'subcontractor_controls' => true, 'business_continuity_terms' => true,
            'termination_assistance' => true, 'service_level_summary' => 'Availability and response commitments retained.',
            'liability_summary' => 'Liability allocation reviewed by the operator.', 'exit_terms_summary' => 'Transition assistance and data disposition addressed.',
            'exceptions_summary' => null, 'decision' => ThirdPartyContractDecision::Approved, 'conditions' => null,
            'rationale' => 'Required risk clauses are present for the retained engagement term.', 'engagement_snapshot' => [], 'risk_approval_snapshot' => [],
            'engagement_event_fingerprint' => str_repeat('0', 64),
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')),
            'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyContractRiskReview $review): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($review->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $engagementSnapshot = $engagement->toArray();
            unset($engagementSnapshot['contract_risk_reviews'], $engagementSnapshot['events']);
            $latestEvent = $engagement->events()->latest('version')->firstOrFail();
            $fields = ['contract_reference', 'agreement_type', 'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at', 'confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance', 'service_level_summary', 'liability_summary', 'exit_terms_summary', 'exceptions_summary', 'decision', 'conditions', 'rationale'];
            $canonicalData = [];
            foreach ($fields as $field) {
                $value = $review->{$field};
                $canonicalData[$field] = match ($field) {
                    'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at' => $value?->toDateString(), 'decision' => $value->value, default => $value,
                };
            }
            $at = now()->startOfSecond();
            $payload = $canonicalData + ['third_party_engagement_id' => $engagement->id, 'version' => $review->version,
                'engagement_snapshot' => $engagementSnapshot, 'risk_approval_snapshot' => $engagement->approval_snapshot,
                'engagement_event_fingerprint' => $latestEvent->fingerprint, 'reviewed_by' => $review->reviewed_by, 'reviewed_at' => $at->toIso8601String()];
            $review->engagement_snapshot = $engagementSnapshot;
            $review->risk_approval_snapshot = $engagement->approval_snapshot;
            $review->engagement_event_fingerprint = $latestEvent->fingerprint;
            $review->reviewed_at = $at;
            $review->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }

    private function approvedEngagement(): ThirdPartyEngagement
    {
        $actors = collect(range(1, 4))->map(fn () => tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')));
        [$proposer, $assessor, $decider, $approver] = $actors->all();
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['status' => VendorStatus::ACCEPTED]);
        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, ['likelihood' => 3, 'impact' => 4, 'residual_likelihood' => 2, 'residual_impact' => 2,
            'risk_categories' => ['cybersecurity'], 'assessment_summary' => 'Factory assessment.', 'treatment_summary' => 'Factory treatment.']);
        $riskManager->mapRisk($vendor, Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, ['rationale' => 'Factory approval.', 'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager = app(ThirdPartyEngagementManager::class);
        $engagement = $manager->propose($proposer, $vendor, ['code' => fake()->unique()->bothify('ENG-FACT-####'), 'name' => 'Factory contract engagement',
            'service_description' => 'Factory service.', 'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => true,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Factory due diligence.']);
        $manager->transition($approver, $engagement, ['status' => 'approved', 'summary' => 'Factory engagement approval.']);

        return $engagement->refresh();
    }
}
