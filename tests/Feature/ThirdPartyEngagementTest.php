<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\User;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use App\ThirdPartyRisk\ThirdPartyEngagementDueDiligenceManager;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOffboardingManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ThirdPartyEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_governed_engagement_moves_from_proposal_through_renewal_and_exit(): void
    {
        [$vendor, $proposer, $assessor, $decider, $approver, $owner, $contractReviewer] = $this->approvedContext();
        Sanctum::actingAs($proposer);
        $payload = [
            'code' => 'ENG-CLOUD-01', 'name' => 'Hosted resilience platform',
            'service_description' => 'Material hosted service supporting continuity operations.',
            'business_owner_id' => $owner->id, 'criticality' => 'critical', 'data_access' => true,
            'term_start_at' => today()->toDateString(), 'term_end_at' => today()->addYear()->toDateString(),
            'next_review_at' => today()->addMonths(6)->toDateString(),
        ];
        $id = $this->postJson("/api/vendors/{$vendor->id}/engagements", $payload + ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = $this->postJson("/api/vendors/{$vendor->id}/engagements", $payload)
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.events.0.version', 1)->json('data.id');
        $engagement = ThirdPartyEngagement::query()->findOrFail($id);

        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'due_diligence', 'summary' => 'Due diligence inputs completed.'])->assertOk();
        $this->recordDueDiligence($contractReviewer, $engagement);
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'approved', 'summary' => 'Self approval attempt.'])->assertForbidden();

        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'approved', 'summary' => 'Independent engagement approval.'])
            ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.engagement_snapshot.approval_snapshot.assessment.assessor_id', $assessor->id)
            ->assertJsonPath('data.engagement_snapshot.approval_snapshot.decision.decided_by', $decider->id);
        Sanctum::actingAs($contractReviewer);
        $this->postJson("/api/third-party-engagements/{$id}/contract-risk-reviews", $this->contractReviewPayload(today()->addYear()))->assertCreated();
        $this->recordOnboarding($engagement);
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'active', 'summary' => 'Term activated after current approval check.'])
            ->assertOk()->assertJsonPath('data.to_status', 'active');
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'renewal_review', 'summary' => 'Engagement entered renewal review.'])->assertOk();

        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, $this->assessmentPayload());
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, [
            'rationale' => 'Renewal risk accepted.', 'conditions' => 'Continue annual assurance.',
            'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $renewalDates = ['proposed_term_end_at' => today()->addYears(2)->toDateString(), 'proposed_next_review_at' => today()->addYear()->toDateString()];
        $this->postJson("/api/third-party-engagements/{$id}/contract-risk-reviews", array_replace($this->contractReviewPayload(today()->addYears(2), 'MSA-INVALID-GAP'), $renewalDates, [
            'effective_at' => today()->addYear()->addDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('effective_at');
        $this->postJson("/api/third-party-engagements/{$id}/contract-risk-reviews", array_replace($this->contractReviewPayload(today()->addYears(2), 'MSA-2026-RENEWAL'), $renewalDates))->assertCreated();
        $this->postJson("/api/third-party-engagements/{$id}/events", [
            'status' => 'active', 'summary' => 'Independent renewal approved against current risk evidence.',
            'renewed_term_end_at' => today()->addYears(2)->toDateString(), 'renewed_next_review_at' => today()->addYear()->toDateString(),
        ])->assertForbidden();
        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$id}/events", [
            'status' => 'active', 'summary' => 'Independent renewal approved against current risk evidence.',
            'renewed_term_end_at' => today()->addYears(2)->toDateString(), 'renewed_next_review_at' => today()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.version', 6);
        $exitOwner = User::factory()->create();
        $exitReviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $offboarding = app(ThirdPartyEngagementOffboardingManager::class);
        $exitRequirement = $offboarding->define($approver, $engagement->refresh(), ['category' => 'access', 'title' => 'Close provider access', 'acceptance_criteria' => 'Provider access is closed.', 'owner_id' => $exitOwner->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true]);
        $offboarding->complete($exitOwner, $exitRequirement, ['completion_summary' => 'Provider access closure was reported.', 'source_reference' => 'EXIT-ACCESS']);
        $offboarding->review($exitReviewer, $engagement->refresh(), ['decision' => 'ready', 'summary' => 'Exit controls are ready.']);
        $this->postJson("/api/third-party-engagements/{$id}/events", [
            'status' => 'exited', 'summary' => 'Engagement exit decision recorded.', 'exit_summary' => 'Service was transitioned to a replacement provider.',
            'data_disposition_statement' => 'Provider attested that organizational data was returned or deleted; execution is not independently verified.',
        ])->assertOk()->assertJsonPath('data.to_status', 'exited');

        $event = ThirdPartyEngagementEvent::query()->latest('id')->firstOrFail();
        $fingerprintPayload = $event->only(['third_party_engagement_id', 'version', 'from_status', 'to_status', 'summary', 'engagement_snapshot', 'recorded_by']);
        $fingerprintPayload['from_status'] = $event->from_status?->value;
        $fingerprintPayload['to_status'] = $event->to_status->value;
        $fingerprintPayload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $this->assertCount(7, $engagement->refresh()->events);
        $this->assertSame(ThirdPartyEngagementStatus::Exited, $engagement->status);
    }

    public function test_history_is_scoped_immutable_and_available_in_the_operator_workspace(): void
    {
        [$vendor, $manager, , , , $owner] = $this->approvedContext();
        $engagement = app(ThirdPartyEngagementManager::class)->propose($manager, $vendor, [
            'code' => 'ENG-SCOPE-01', 'name' => 'Scoped service', 'service_description' => 'Scoped engagement evidence.',
            'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => false,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Vendors');
        Sanctum::actingAs($reader);
        $this->getJson("/api/vendors/{$vendor->id}/engagements?per_page=100")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [])->assertForbidden();
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/third-party-engagements/{$engagement->id}")->assertForbidden();

        $this->actingAs($reader, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertCanSeeTableRecords([$engagement])->assertTableActionVisible('inspect', $engagement)->assertTableActionHidden('transition', $engagement);

        $event = $engagement->events()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['summary' => 'Rewritten evidence']);
    }

    public function test_factories_and_retained_migration_preserve_reconstructible_evidence(): void
    {
        $event = ThirdPartyEngagementEvent::factory()->create();
        $event->refresh()->load('engagement');
        $this->assertSame($event->engagement->proposed_by, $event->recorded_by);
        $payload = $event->only(['third_party_engagement_id', 'version', 'from_status', 'to_status', 'summary', 'engagement_snapshot', 'recorded_by']);
        $payload['from_status'] = $event->from_status?->value;
        $payload['to_status'] = $event->to_status->value;
        $payload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $migration = include database_path('migrations/2026_08_24_750000_create_third_party_engagement_history.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);

        $contractReview = ThirdPartyContractRiskReview::factory()->create();
        $payload = [];
        foreach (['contract_reference', 'agreement_type', 'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at', 'confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance', 'service_level_summary', 'liability_summary', 'exit_terms_summary', 'exceptions_summary', 'decision', 'conditions', 'rationale'] as $field) {
            $payload[$field] = match ($field) {
                'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at' => $contractReview->{$field}?->toDateString(), 'decision' => $contractReview->decision->value, default => $contractReview->{$field},
            };
        }
        $payload += $contractReview->only(['third_party_engagement_id', 'version', 'engagement_snapshot', 'risk_approval_snapshot', 'engagement_event_fingerprint', 'reviewed_by']);
        $payload['reviewed_at'] = $contractReview->reviewed_at->toIso8601String();
        $this->assertSame($contractReview->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $contractMigration = include database_path('migrations/2026_08_24_760000_create_third_party_contract_risk_reviews.php');
        $contractMigration->down();
        $this->assertDatabaseHas('third_party_contract_risk_reviews', ['id' => $contractReview->id, 'fingerprint' => $contractReview->fingerprint]);
    }

    public function test_activation_requires_an_independent_current_contract_risk_review(): void
    {
        [$vendor, $proposer, , , $approver, $owner, $reviewer] = $this->approvedContext();
        $engagementManager = app(ThirdPartyEngagementManager::class);
        $engagement = $engagementManager->propose($proposer, $vendor, [
            'code' => 'ENG-CONTRACT-01', 'name' => 'Contract-governed service',
            'service_description' => 'Service requiring contract risk review before activation.',
            'business_owner_id' => $owner->id, 'criticality' => 'critical', 'data_access' => true,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $engagementManager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Due diligence completed.']);
        $this->recordDueDiligence($reviewer, $engagement);
        $engagementManager->transition($approver, $engagement, ['status' => 'approved', 'summary' => 'Engagement independently approved.']);

        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'active', 'summary' => 'Attempt activation without contract review.',
        ])->assertUnprocessable()->assertJsonValidationErrors('contract_review');

        $contractReviewer = User::factory()->create();
        $contractReviewer->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']);
        Sanctum::actingAs($contractReviewer);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/contract-risk-reviews", $this->contractReviewPayload(today()->addYear()) + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/contract-risk-reviews", $this->contractReviewPayload(today()->addYear()))->assertForbidden();
        Sanctum::actingAs($contractReviewer);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/contract-risk-reviews", array_replace($this->contractReviewPayload(today()->addYear()), ['audit_rights' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson("/api/third-party-engagements/{$engagement->id}/contract-risk-reviews", [
            'contract_reference' => 'MSA-2026-0042', 'agreement_type' => 'master_service',
            'effective_at' => today()->toDateString(), 'expires_at' => today()->addYear()->toDateString(),
            'confidentiality_terms' => 1, 'data_protection_terms' => 1, 'incident_notification_terms' => 1,
            'audit_rights' => 1, 'subcontractor_controls' => 1, 'business_continuity_terms' => 1,
            'termination_assistance' => 1, 'service_level_summary' => 'Availability and response commitments retained.',
            'liability_summary' => 'Liability allocation reviewed by the operator.', 'exit_terms_summary' => 'Transition assistance and data disposition addressed.',
            'decision' => 'approved', 'rationale' => 'Required risk clauses are present for the retained engagement term.',
        ])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.decision', 'approved');
        $numericBooleanReview = $engagement->contractRiskReviews()->firstOrFail();
        $this->assertTrue($numericBooleanReview->audit_rights);
        $this->assertContractReviewFingerprint($numericBooleanReview);

        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Vendors');
        Sanctum::actingAs($reader);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/contract-risk-reviews?per_page=100")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.contract_reference', 'MSA-2026-0042');

        Sanctum::actingAs($contractReviewer);
        $this->recordOnboarding($engagement);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'active', 'summary' => 'Activated after current contract and vendor-risk reviews.',
        ])->assertOk()->assertJsonPath('data.to_status', 'active');
        $this->view('filament.third-party-engagement', ['engagement' => $engagement->fresh(['businessOwner', 'events.actor', 'contractRiskReviews.reviewer'])])
            ->assertSee('MSA-2026-0042')->assertSee('Contract-risk review history')->assertSee('Incident notification')
            ->assertSee('Availability and response commitments retained.')->assertSee('Vendor-risk approval snapshot')
            ->assertSee($engagement->contractRiskReviews()->firstOrFail()->engagement_event_fingerprint);
    }

    public function test_stale_risk_or_contract_context_requires_attributable_reapproval(): void
    {
        [$vendor, $proposer, $assessor, $decider, $approver, $owner, $contractReviewer] = $this->approvedContext();
        $manager = app(ThirdPartyEngagementManager::class);
        $engagement = $manager->propose($proposer, $vendor, ['code' => 'ENG-REAPPROVE-01', 'name' => 'Reapproval service',
            'service_description' => 'Service with changing risk context.', 'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => true,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Initial due diligence.']);
        $this->recordDueDiligence($contractReviewer, $engagement);
        $manager->transition($approver, $engagement, ['status' => 'approved', 'summary' => 'Initial approval.']);
        app(ThirdPartyContractRiskManager::class)->review($contractReviewer, $engagement, $this->contractReviewPayload(today()->addYear()));

        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, $this->assessmentPayload());
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, ['rationale' => 'Changed risk context approved.', 'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Stale activation.'])
            ->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'due_diligence', 'summary' => 'Returned for current-context review.'])->assertOk();
        $this->recordDueDiligence($contractReviewer, $engagement);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'approved', 'summary' => 'Reapproved against current risk context.'])->assertOk();
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Old contract review is stale.'])
            ->assertUnprocessable()->assertJsonValidationErrors('contract_review');
        app(ThirdPartyContractRiskManager::class)->review($contractReviewer, $engagement, $this->contractReviewPayload(today()->addYear(), 'MSA-2026-REAPPROVED'));
        $this->recordOnboarding($engagement);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Activated after both reapprovals.'])->assertOk();
    }

    private function approvedContext(): array
    {
        $actors = collect(range(1, 5))->map(fn () => tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors', 'Read Surveys'])));
        [$proposer, $assessor, $decider, $approver] = $actors->take(4)->all();
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $proposer->id, 'status' => VendorStatus::ACCEPTED]);
        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, $this->assessmentPayload());
        $riskManager->mapRisk($vendor, Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, [
            'rationale' => 'Residual exposure accepted.', 'conditions' => 'Annual reassessment.',
            'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);

        $contractReviewer = User::factory()->create();
        $contractReviewer->givePermissionTo(['Manage Third Party Risk', 'Read Vendors', 'Read Surveys']);

        return [$vendor, $proposer, $assessor, $decider, $approver, $owner, $contractReviewer];
    }

    private function recordDueDiligence(User $reviewer, ThirdPartyEngagement $engagement): void
    {
        $survey = Survey::factory()->create(['vendor_id' => $engagement->vendor_id, 'type' => SurveyType::VENDOR_ASSESSMENT,
            'status' => SurveyStatus::COMPLETED, 'risk_score' => 75, 'risk_score_calculated_at' => now()->startOfSecond(), 'completed_at' => now()->subDay()]);
        app(ThirdPartyEngagementDueDiligenceManager::class)->review($reviewer, $engagement, [
            'survey_id' => $survey->id, 'cybersecurity_rating' => 3, 'privacy_rating' => 3, 'resilience_rating' => 3,
            'compliance_rating' => 3, 'financial_rating' => 3, 'findings_summary' => 'Factory-aligned due diligence findings.',
            'decision' => 'satisfactory', 'rationale' => 'Current survey and risk approval support progression.',
            'next_review_at' => $engagement->next_review_at->toDateString(),
        ]);
    }

    private function assessmentPayload(): array
    {
        return ['likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'risk_categories' => ['cybersecurity', 'privacy', 'operational'], 'assessment_summary' => 'Material hosted service exposure.',
            'treatment_summary' => 'Contractual controls, assurance review, notification, and exit planning.'];
    }

    private function recordOnboarding(ThirdPartyEngagement $engagement): void
    {
        $managerActor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $owner = User::factory()->create();
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $manager = app(ThirdPartyEngagementOnboardingManager::class);
        $requirement = $manager->define($managerActor, $engagement, ['category' => 'security', 'title' => 'Factory-aligned onboarding control', 'acceptance_criteria' => 'Required access and security configuration is complete.', 'owner_id' => $owner->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true]);
        $manager->complete($owner, $requirement, ['completion_summary' => 'Required onboarding control completed.', 'source_reference' => 'ONBOARD-TEST']);
        $manager->review($reviewer, $engagement, ['decision' => 'ready', 'summary' => 'Required onboarding completion is attributable and ready.', 'next_review_at' => $engagement->next_review_at->toDateString()]);
    }

    private function contractReviewPayload(\DateTimeInterface $expiresAt, string $reference = 'MSA-2026-0042'): array
    {
        return ['contract_reference' => $reference, 'agreement_type' => 'master_service', 'effective_at' => today()->toDateString(), 'expires_at' => $expiresAt->format('Y-m-d'),
            'confidentiality_terms' => true, 'data_protection_terms' => true, 'incident_notification_terms' => true, 'audit_rights' => true,
            'subcontractor_controls' => true, 'business_continuity_terms' => true, 'termination_assistance' => true,
            'service_level_summary' => 'Availability and response commitments retained.', 'liability_summary' => 'Liability allocation reviewed by the operator.',
            'exit_terms_summary' => 'Transition assistance and data disposition addressed.', 'decision' => 'approved',
            'rationale' => 'Required risk clauses are present for the retained engagement term.'];
    }

    private function assertContractReviewFingerprint(ThirdPartyContractRiskReview $review): void
    {
        $payload = [];
        foreach (['contract_reference', 'agreement_type', 'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at', 'confidentiality_terms', 'data_protection_terms', 'incident_notification_terms', 'audit_rights', 'subcontractor_controls', 'business_continuity_terms', 'termination_assistance', 'service_level_summary', 'liability_summary', 'exit_terms_summary', 'exceptions_summary', 'decision', 'conditions', 'rationale'] as $field) {
            $payload[$field] = match ($field) {
                'effective_at', 'expires_at', 'proposed_term_end_at', 'proposed_next_review_at' => $review->{$field}?->toDateString(), 'decision' => $review->decision->value, default => $review->{$field},
            };
        }
        $payload += $review->only(['third_party_engagement_id', 'version', 'engagement_snapshot', 'risk_approval_snapshot', 'engagement_event_fingerprint', 'reviewed_by']);
        $payload['reviewed_at'] = $review->reviewed_at->toIso8601String();
        $this->assertSame($review->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }
}
