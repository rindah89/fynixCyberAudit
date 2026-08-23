<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Filament\Exports\VendorExporter;
use App\Filament\Resources\ThirdPartyRiskResource;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRiskAssessment;
use App\Models\VendorRiskIssue;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThirdPartyRiskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_records_versioned_vendor_risk_assessment_with_server_scores(): void
    {
        $manager = $this->manager();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        Sanctum::actingAs($manager);
        $payload = $this->assessmentPayload();

        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $payload + ['version' => 99, 'inherent_score' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['version', 'inherent_score']);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.inherent_score', 20)->assertJsonPath('data.residual_score', 6)
            ->assertJsonPath('vendor.third_party_risk_status', 'risk_link_required');
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $payload)
            ->assertCreated()->assertJsonPath('data.version', 2);
    }

    public function test_assessment_may_snapshot_only_a_scored_survey_for_the_same_vendor(): void
    {
        $manager = $this->manager();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        $otherSurvey = Survey::factory()->create(['risk_score' => 35, 'risk_score_calculated_at' => now()]);
        $wrongType = Survey::factory()->create(['vendor_id' => $vendor->id, 'type' => SurveyType::QUESTIONNAIRE, 'status' => SurveyStatus::COMPLETED, 'risk_score' => 60, 'risk_score_calculated_at' => now()]);
        $missingProvenance = Survey::factory()->create(['vendor_id' => $vendor->id, 'status' => SurveyStatus::COMPLETED, 'risk_score' => 55, 'risk_score_calculated_at' => null]);
        $survey = Survey::factory()->create(['vendor_id' => $vendor->id, 'status' => SurveyStatus::COMPLETED, 'risk_score' => 72, 'risk_score_calculated_at' => now()]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload() + ['survey_id' => $otherSurvey->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['survey_id']);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload() + ['survey_id' => $wrongType->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['survey_id']);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload() + ['survey_id' => $missingProvenance->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['survey_id']);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload() + ['survey_id' => $survey->id])
            ->assertCreated()->assertJsonPath('data.survey_score_snapshot', 72);
    }

    public function test_vendor_may_link_only_governed_third_party_risks(): void
    {
        $manager = $this->manager();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        $technologyRisk = Risk::factory()->create(['domain' => RiskDomain::Technology]);
        $thirdPartyRisk = Risk::factory()->create(['domain' => RiskDomain::ThirdParty]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/vendors/{$vendor->id}/risks", ['risk_id' => $technologyRisk->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['risk_id']);
        $this->postJson("/api/vendors/{$vendor->id}/risks", ['risk_id' => $thirdPartyRisk->id])
            ->assertCreated()->assertJsonPath('data.domain', 'third_party');
    }

    public function test_decision_requires_current_assessment_and_risk_and_snapshots_governance(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $manager = $this->manager();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $payload = ['decision' => 'approved', 'rationale' => 'Residual exposure is within tolerance.', 'conditions' => 'Annual reassessment.', 'expires_at' => '2027-08-23', 'next_review_at' => '2027-02-23'];
        $this->postJson("/api/vendors/{$vendor->id}/risk-decisions", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['assessment', 'risks']);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload())->assertCreated();
        $risk = Risk::factory()->create(['domain' => RiskDomain::ThirdParty]);
        $this->postJson("/api/vendors/{$vendor->id}/risks", ['risk_id' => $risk->id])->assertCreated();

        $risk->update(['domain' => RiskDomain::Technology]);
        $this->postJson("/api/vendors/{$vendor->id}/risk-decisions", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['risks']);
        $risk->update(['domain' => RiskDomain::ThirdParty]);

        $this->postJson("/api/vendors/{$vendor->id}/risk-decisions", $payload)
            ->assertCreated()->assertJsonPath('data.assessment_version', 1)
            ->assertJsonPath('data.residual_score', 6)->assertJsonPath('data.risk_ids.0', $risk->id)
            ->assertJsonPath('vendor.third_party_risk_status', 'approved');

        $risk->update(['domain' => RiskDomain::Technology]);
        $this->assertSame('reapproval_required', $vendor->fresh()->third_party_risk_status);
        $risk->update(['domain' => RiskDomain::ThirdParty]);
        $this->assertSame('approved', $vendor->fresh()->third_party_risk_status);

        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload())
            ->assertCreated()->assertJsonPath('vendor.third_party_risk_status', 'reapproval_required');
    }

    public function test_review_is_attributable_to_approval_and_opens_persistent_issue(): void
    {
        $manager = $this->manager();
        $vendor = $this->approvedVendor($manager);
        Sanctum::actingAs($manager);

        $this->postJson("/api/vendors/{$vendor->id}/risk-reviews", [
            'outcome' => 'needs_action', 'summary' => 'The vendor reported a material control exception.',
            'evidence_reference' => 'TPRM-REVIEW-2026-Q3', 'next_review_at' => now()->addMonth(),
        ])->assertCreated()->assertJsonPath('data.assessment_version', 1)
            ->assertJsonPath('data.issue.status', 'open')->assertJsonPath('vendor.third_party_risk_status', 'action_required');
        $issue = VendorRiskIssue::query()->where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_type' => VendorRiskIssue::class, 'issue_id' => $issue->id, 'status' => 'open']);

        $this->postJson("/api/vendors/{$vendor->id}/risk-reviews", [
            'outcome' => 'satisfactory', 'summary' => 'Follow-up evidence was reviewed; the issue remains open.',
            'next_review_at' => now()->addMonths(2),
        ])->assertCreated()->assertJsonPath('vendor.third_party_risk_status', 'action_required');
    }

    public function test_terminal_decision_remains_visible_after_inventory_changes(): void
    {
        $manager = $this->manager();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        VendorRiskAssessment::factory()->create(['vendor_id' => $vendor->id, 'assessor_id' => $manager->id]);
        $vendor->risks()->attach(Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        app(ThirdPartyRiskManager::class)->decide($vendor, $manager, ThirdPartyRiskDecisionType::Rejected, [
            'rationale' => 'The exposure exceeds risk appetite.',
        ]);

        $vendor->update(['name' => 'Renamed terminated relationship']);

        $this->assertSame('rejected', $vendor->fresh()->third_party_risk_status);
    }

    public function test_assessments_decisions_and_reviews_are_append_only_through_models(): void
    {
        $manager = $this->manager();
        $vendor = $this->approvedVendor($manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/vendors/{$vendor->id}/risk-reviews", [
            'outcome' => 'satisfactory', 'summary' => 'No material changes identified.', 'next_review_at' => now()->addMonth(),
        ])->assertCreated();

        foreach ([$vendor->riskAssessments()->firstOrFail(), $vendor->riskDecisions()->firstOrFail(), $vendor->riskReviews()->firstOrFail()] as $record) {
            try {
                $record->delete();
                $this->fail($record::class.' was deletable.');
            } catch (\LogicException) {
                $this->assertDatabaseHas($record->getTable(), ['id' => $record->id]);
            }
        }
    }

    public function test_vendor_owner_can_discover_workspace_but_only_manager_can_govern(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $owner->id]);
        Vendor::factory()->create();

        $this->actingAs($owner)->get(ThirdPartyRiskResource::getUrl('index'))->assertOk();
        $this->get(ThirdPartyRiskResource::getUrl('view', ['record' => $vendor]))->assertOk();
        $this->assertSame([$vendor->id], ThirdPartyRiskResource::getEloquentQuery()->pluck('id')->all());
        Sanctum::actingAs($owner);
        $this->postJson("/api/vendors/{$vendor->id}/risk-assessments", $this->assessmentPayload())->assertForbidden();
    }

    public function test_vendor_export_includes_governed_third_party_risk_state(): void
    {
        $columns = collect(VendorExporter::getColumns())->map->getName();

        $this->assertContains('third_party_risk_status', $columns);
        $this->assertContains('latestRiskAssessment.version', $columns);
        $this->assertContains('latestRiskDecision.decision', $columns);
        $this->assertContains('risks_count', $columns);

        $manager = $this->manager();
        $vendor = $this->approvedVendor($manager);
        $exported = VendorExporter::modifyQuery(Vendor::query()->whereKey($vendor))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('vendorManager'));
        $this->assertTrue($exported->relationLoaded('latestRiskAssessment'));
        $this->assertTrue($exported->relationLoaded('latestRiskDecision'));
        $this->assertSame(1, $exported->risks_count);
        $this->assertSame('approved', $exported->third_party_risk_status);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['Manage Third Party Risk', 'List Vendors', 'Read Vendors']);

        return $user;
    }

    private function assessmentPayload(): array
    {
        return [
            'likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'risk_categories' => ['cybersecurity', 'privacy', 'operational'],
            'assessment_summary' => 'Critical hosted service with material security and availability exposure.',
            'treatment_summary' => 'Contractual controls, assurance review, incident notification, and exit planning.',
        ];
    }

    private function approvedVendor(User $manager): Vendor
    {
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        VendorRiskAssessment::factory()->create(['vendor_id' => $vendor->id, 'assessor_id' => $manager->id]);
        $vendor->risks()->attach(Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        app(ThirdPartyRiskManager::class)->decide($vendor, $manager, ThirdPartyRiskDecisionType::Approved, [
            'rationale' => 'Residual exposure accepted.', 'conditions' => 'Annual reassessment.',
            'expires_at' => now()->addYear(), 'next_review_at' => now()->addMonths(6),
        ]);

        return $vendor;
    }
}
