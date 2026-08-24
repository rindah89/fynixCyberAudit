<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\ThirdPartyEngagementDueDiligenceReview;
use App\Models\User;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ThirdPartyEngagementDueDiligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_structured_due_diligence_review_gates_independent_engagement_approval(): void
    {
        [$vendor, $proposer, $reviewer, $approver, $owner] = $this->vendorContext();
        $manager = app(ThirdPartyEngagementManager::class);
        $engagement = $manager->propose($proposer, $vendor, [
            'code' => 'ENG-DD-001', 'name' => 'Governed due diligence service', 'service_description' => 'Critical hosted service.',
            'business_owner_id' => $owner->id, 'criticality' => 'critical', 'data_access' => true,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $manager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Due diligence opened.']);
        $survey = Survey::factory()->create(['vendor_id' => $vendor->id, 'type' => SurveyType::VENDOR_ASSESSMENT,
            'status' => SurveyStatus::COMPLETED, 'risk_score' => 72, 'risk_score_calculated_at' => now()->startOfSecond(), 'completed_at' => now()->subDay()]);

        Sanctum::actingAs($reviewer);
        $reviewId = $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", [
            'survey_id' => $survey->id, 'cybersecurity_rating' => 3, 'privacy_rating' => 3, 'resilience_rating' => 2,
            'compliance_rating' => 2, 'financial_rating' => 2, 'findings_summary' => 'No critical unresolved findings.',
            'decision' => 'satisfactory', 'rationale' => 'Completed assessment and current risk approval support progression.',
            'next_review_at' => today()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.survey_snapshot.survey.risk_score', 72)
            ->assertJsonPath('data.engagement_event_fingerprint', $engagement->events()->reorder()->orderByDesc('version')->value('fingerprint'))
            ->json('data.id');

        $review = ThirdPartyEngagementDueDiligenceReview::query()->findOrFail($reviewId);
        $this->assertReviewFingerprint($review);
        $reader = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Read Vendors']));
        Sanctum::actingAs($reader);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews")
            ->assertOk()->assertJsonPath('data.0.survey_snapshot', null)->assertJsonPath('data.0.document_snapshots', []);
        Sanctum::actingAs($reviewer);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews")
            ->assertOk()->assertJsonPath('data.0.survey_snapshot.survey.risk_score', 72);
        $this->actingAs($reviewer, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertCanSeeTableRecords([$engagement])->assertTableActionVisible('due_diligence_review', $engagement);
        $this->view('filament.third-party-engagement', ['engagement' => $engagement->fresh(['businessOwner', 'events.actor', 'contractRiskReviews.reviewer', 'dueDiligenceReviews.reviewer', 'monitoringIndicators.latestObservations'])])
            ->assertSee('Structured due-diligence review history')->assertSee('No critical unresolved findings.');
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'approved', 'summary' => 'Reviewer self-approval.'])->assertForbidden();
        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'approved', 'summary' => 'Independent approval after due diligence.'])
            ->assertOk()->assertJsonPath('data.engagement_snapshot.due_diligence_review_snapshot.id', $reviewId);

        $migration = require database_path('migrations/2026_08_24_780000_create_third_party_engagement_due_diligence_reviews.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_due_diligence_reviews', ['id' => $reviewId, 'fingerprint' => $review->fingerprint]);
    }

    public function test_due_diligence_rejects_wrong_source_conditional_without_conditions_and_unsatisfactory_approval(): void
    {
        [$vendor, $proposer, $reviewer, $approver, $owner] = $this->vendorContext();
        $manager = app(ThirdPartyEngagementManager::class);
        $engagement = $manager->propose($proposer, $vendor, ['code' => 'ENG-DD-002', 'name' => 'Conditional diligence service',
            'service_description' => 'Structured review validation.', 'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => true,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Review opened.']);
        $foreignSurvey = Survey::factory()->create(['vendor_id' => Vendor::factory(), 'type' => SurveyType::VENDOR_ASSESSMENT,
            'status' => SurveyStatus::COMPLETED, 'risk_score' => 60, 'risk_score_calculated_at' => now(), 'completed_at' => now()]);
        $payload = ['survey_id' => $foreignSurvey->id, 'cybersecurity_rating' => 2, 'privacy_rating' => 2, 'resilience_rating' => 2,
            'compliance_rating' => 2, 'financial_rating' => 2, 'findings_summary' => 'Material issues remain.', 'decision' => 'conditional',
            'rationale' => 'Additional controls are required.', 'next_review_at' => today()->addMonths(6)->toDateString(), 'fingerprint' => 'caller-owned'];
        $sourceBlindManager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        Sanctum::actingAs($sourceBlindManager);
        $probe = array_diff_key($payload, ['fingerprint' => true]);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $probe)->assertForbidden();
        $probe['survey_id'] = 999999;
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $probe)->assertForbidden();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        unset($payload['fingerprint']);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('survey_id');
        $survey = Survey::factory()->create(['vendor_id' => $vendor->id, 'type' => SurveyType::VENDOR_ASSESSMENT,
            'status' => SurveyStatus::COMPLETED, 'risk_score' => 60, 'risk_score_calculated_at' => now(), 'completed_at' => now()]);
        $payload['survey_id'] = $survey->id;
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $payload['decision'] = 'unsatisfactory';
        $this->postJson("/api/third-party-engagements/{$engagement->id}/due-diligence-reviews", $payload)->assertCreated();
        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'approved', 'summary' => 'Cannot approve.'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_diligence_review');

        $factoryReview = ThirdPartyEngagementDueDiligenceReview::factory()->create();
        $this->assertReviewFingerprint($factoryReview);
        $this->assertSame($factoryReview->survey_id, data_get($factoryReview->survey_snapshot, 'survey.id'));
    }

    private function vendorContext(): array
    {
        $actors = collect(range(1, 5))->map(fn () => tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors', 'Read Surveys'])));
        [$proposer, $assessor, $decider, $reviewer, $approver] = $actors->all();
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $proposer->id, 'status' => VendorStatus::ACCEPTED]);
        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, ['likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'risk_categories' => ['cybersecurity', 'privacy'], 'assessment_summary' => 'Material service.', 'treatment_summary' => 'Contract and diligence controls.']);
        $riskManager->mapRisk($vendor, Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, ['rationale' => 'Residual exposure accepted.',
            'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);

        return [$vendor, $proposer, $reviewer, $approver, $owner];
    }

    private function assertReviewFingerprint(ThirdPartyEngagementDueDiligenceReview $review): void
    {
        $payload = $review->only(['third_party_engagement_id', 'version', 'survey_id', 'cybersecurity_rating', 'privacy_rating', 'resilience_rating', 'compliance_rating', 'financial_rating', 'findings_summary']);
        $payload += ['decision' => $review->decision->value, 'conditions' => $review->conditions, 'rationale' => $review->rationale,
            'next_review_at' => $review->next_review_at->toDateString(), 'engagement_snapshot' => $review->engagement_snapshot,
            'survey_snapshot' => $review->survey_snapshot, 'document_snapshots' => $review->document_snapshots,
            'risk_approval_snapshot' => $review->risk_approval_snapshot, 'engagement_event_fingerprint' => $review->engagement_event_fingerprint,
            'reviewed_by' => $review->reviewed_by, 'reviewed_at' => $review->reviewed_at->toIso8601String()];
        $this->assertSame($review->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }
}
