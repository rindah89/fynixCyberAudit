<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Filament\Exports\VendorExporter;
use App\Filament\Resources\ThirdPartyRiskResource;
use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\FourthPartyDependenciesRelationManager;
use App\Filament\Resources\VendorResource\RelationManagers\RiskReviewsRelationManager;
use App\Models\Audit;
use App\Models\BusinessService;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFourthPartyDependency;
use App\Models\VendorRiskAssessment;
use App\Models\VendorRiskIssue;
use App\Models\VendorRiskReviewEvidence;
use App\Services\FourthPartyDependencyManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ThirdPartyRiskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('private');
    }

    public function test_manager_records_versioned_fourth_party_dependencies_and_derives_concentration(): void
    {
        $manager = $this->manager();
        $primary = Vendor::factory()->create(['vendor_manager_id' => $manager->id]);
        $secondPrimary = Vendor::factory()->create();
        $fourthParty = Vendor::factory()->create(['name' => 'Shared Cloud Subprocessor']);
        $service = BusinessService::factory()->create();
        Sanctum::actingAs($manager);
        $payload = [
            'fourth_party_vendor_id' => $fourthParty->id,
            'business_service_id' => $service->id,
            'status' => 'active', 'category' => 'cloud_infrastructure', 'criticality' => 'critical',
            'service_description' => 'Hosts production workloads for the primary vendor.',
            'data_access' => true, 'source_reference' => 'DDQ-2026-44',
            'rationale' => 'The vendor disclosed this material subprocessor.',
        ];

        $this->postJson("/api/vendors/{$primary->id}/fourth-party-dependencies", $payload + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $first = $this->postJson("/api/vendors/{$primary->id}/fourth-party-dependencies", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.governance_snapshot.fourth_party.name', 'Shared Cloud Subprocessor')
            ->assertJsonPath('data.governance_snapshot.business_service.id', $service->id)
            ->assertJsonPath('concentration.primary_vendor_count', 1)
            ->assertJsonPath('concentration.high_or_critical_count', 1)
            ->assertJsonPath('concentration.concentration_band', 'moderate')->json('data.id');
        $this->postJson("/api/vendors/{$secondPrimary->id}/fourth-party-dependencies", $payload)
            ->assertCreated()->assertJsonPath('concentration.primary_vendor_count', 2)
            ->assertJsonPath('concentration.concentration_band', 'moderate');

        $externalName = 'Boundary External Fourth Party';
        $longDescription = str_repeat('S', 2000);
        $external = $this->postJson("/api/vendors/{$primary->id}/fourth-party-dependencies", [
            'fourth_party_name' => $externalName, 'status' => 'active', 'category' => 'other',
            'criticality' => 'low', 'service_description' => $longDescription,
            'rationale' => 'Boundary-width validation.',
        ])->assertCreated()->json('data');
        $this->assertSame(73, strlen($external['dependency_key']));
        $this->assertSame($longDescription, $external['service_description']);
        $concentrations = $this->getJson('/api/third-party-risk/fourth-party-concentrations?per_page=100')
            ->assertOk()->assertJsonPath('per_page', 100)
            ->assertJsonFragment(['fourth_party_name' => 'Shared Cloud Subprocessor', 'primary_vendor_count' => 2]);
        $shared = collect($concentrations->json('data'))->firstWhere('fourth_party_name', 'Shared Cloud Subprocessor');
        $this->assertCount(2, $shared['primary_vendors']);
        $secondPrimary->delete();
        $this->getJson('/api/third-party-risk/fourth-party-concentrations')
            ->assertOk()->assertJsonFragment(['fourth_party_name' => 'Shared Cloud Subprocessor', 'primary_vendor_count' => 1]);
        $secondPrimary->restore();

        $this->postJson("/api/vendors/{$primary->id}/fourth-party-dependencies", $payload + ['fourth_party_name' => 'Conflicting identity'])
            ->assertUnprocessable()->assertJsonValidationErrors('fourth_party_name');

        $this->postJson("/api/vendors/{$primary->id}/fourth-party-dependencies", array_merge($payload, ['status' => 'exited']))
            ->assertCreated()->assertJsonPath('data.version', 2)
            ->assertJsonPath('concentration.primary_vendor_count', 1);
        $this->getJson("/api/vendors/{$primary->id}/fourth-party-dependencies")
            ->assertOk()->assertJsonPath('total', 3)->assertJsonFragment(['status' => 'exited', 'version' => 2]);

        $record = VendorFourthPartyDependency::query()->findOrFail($first);
        $factoryRecord = VendorFourthPartyDependency::factory()->create();
        $this->assertSame($factoryRecord->vendor_id, data_get($factoryRecord->governance_snapshot, 'primary_vendor.id'));
        try {
            $record->update(['rationale' => 'Rewritten']);
            $this->fail('Fourth-party history was mutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('vendor_fourth_party_dependencies', ['id' => $first, 'rationale' => $payload['rationale']]);
        }
    }

    public function test_fourth_party_workspace_preserves_owner_scope_and_cross_vendor_privacy(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $owner->id]);
        $other = Vendor::factory()->create();
        $record = app(FourthPartyDependencyManager::class)->record($vendor, $manager, [
            'fourth_party_name' => 'External Hosting Chain', 'status' => 'active',
            'category' => 'technology_service', 'criticality' => 'high',
            'service_description' => 'Supports the vendor service.', 'rationale' => 'Disclosed in due diligence.',
        ]);
        app(FourthPartyDependencyManager::class)->record($other, $manager, [
            'fourth_party_name' => 'External Hosting Chain', 'status' => 'active',
            'category' => 'technology_service', 'criticality' => 'medium',
            'service_description' => 'Supports another vendor.', 'rationale' => 'Disclosed in due diligence.',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/vendors/{$vendor->id}/fourth-party-dependencies")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/vendors/{$other->id}/fourth-party-dependencies")->assertForbidden();
        $this->getJson('/api/third-party-risk/fourth-party-concentrations')->assertForbidden();
        $this->postJson("/api/vendors/{$vendor->id}/fourth-party-dependencies", [])->assertForbidden();

        $this->actingAs($owner, 'web');
        Livewire::test(FourthPartyDependenciesRelationManager::class, [
            'ownerRecord' => $vendor, 'pageClass' => ViewThirdPartyRisk::class,
        ])->assertCanSeeTableRecords([$record])->assertTableActionVisible('inspect', $record);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/vendors/{$vendor->id}/fourth-party-dependencies")->assertForbidden();

        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Vendors');
        $this->actingAs($reader, 'web');
        $this->assertTrue(ThirdPartyRiskResource::canViewAny());
        $this->assertTrue(ThirdPartyRiskResource::canView($other));
        $this->assertSame(2, ThirdPartyRiskResource::getEloquentQuery()->whereKey([$vendor->id, $other->id])->count());
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

    public function test_periodic_review_can_bind_authorized_accepted_audit_evidence(): void
    {
        $manager = $this->manager();
        $vendor = $this->approvedVendor($manager);
        $attachment = $this->acceptedEvidence($manager, 'vendor-review/assurance.pdf', 'vendor assurance bytes');
        Sanctum::actingAs($manager);

        $reviewId = $this->postJson("/api/vendors/{$vendor->id}/risk-reviews", [
            'outcome' => 'satisfactory', 'summary' => 'Accepted assurance evidence supports the periodic review.',
            'evidence_attachment_ids' => [$attachment->id], 'next_review_at' => now()->addMonth(),
        ])->assertCreated()
            ->assertJsonPath('data.evidence.0.file_attachment_id', $attachment->id)
            ->assertJsonPath('data.evidence.0.sha256', hash('sha256', 'vendor assurance bytes'))
            ->json('data.id');

        $evidence = VendorRiskReviewEvidence::query()->firstOrFail();
        $this->assertSame($reviewId, $evidence->vendor_risk_review_id);
        $this->assertSame('Accepted', $evidence->response_status_snapshot);
        $migration = require database_path('migrations/2026_08_23_260000_create_vendor_risk_review_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('vendor_risk_review_evidence', ['id' => $evidence->id, 'sha256' => $evidence->sha256]);
        Storage::disk('private')->put($attachment->file_path, 'later source replacement');
        $this->actingAs($manager, 'web')->get(route('vendor-risk-review-evidence.download', $evidence))
            ->assertSuccessful()->assertStreamedContent('vendor assurance bytes');
        $this->actingAs(User::factory()->create(), 'web')->get(route('vendor-risk-review-evidence.download', $evidence))
            ->assertForbidden();

        $foreign = $this->acceptedEvidence(User::factory()->create(), 'vendor-review/foreign.pdf', 'foreign bytes');
        $retainedBefore = Storage::disk('private')->allFiles('governed-evidence/vendor-risk-review');
        Sanctum::actingAs($manager);
        $this->postJson("/api/vendors/{$vendor->id}/risk-reviews", [
            'outcome' => 'satisfactory', 'summary' => 'The mixed evidence set must reject atomically.',
            'evidence_attachment_ids' => [$attachment->id, $foreign->id], 'next_review_at' => now()->addMonths(2),
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');
        $this->assertDatabaseCount('vendor_risk_reviews', 1);
        $this->assertSame($retainedBefore, Storage::disk('private')->allFiles('governed-evidence/vendor-risk-review'));

        try {
            $evidence->delete();
            $this->fail('Vendor review evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('vendor_risk_review_evidence', ['id' => $evidence->id]);
        }
        try {
            $attachment->delete();
            $this->fail('A governed source attachment was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('file_attachments', ['id' => $attachment->id]);
        }

        $viewer = User::factory()->create();
        $vendor->update(['vendor_manager_id' => $viewer->id]);
        $this->actingAs($viewer, 'web');
        Livewire::test(RiskReviewsRelationManager::class, [
            'ownerRecord' => $vendor->fresh(), 'pageClass' => ViewThirdPartyRisk::class,
        ])->assertCanSeeTableRecords([$vendor->riskReviews()->firstOrFail()])
            ->assertTableColumnStateSet('evidence_count', 0, $vendor->riskReviews()->firstOrFail())
            ->assertTableActionHidden('inspect_evidence', $vendor->riskReviews()->firstOrFail());
        $vendor->update(['vendor_manager_id' => $manager->id]);
    }

    public function test_review_service_reauthorizes_before_creating_review_or_evidence(): void
    {
        $manager = $this->manager();
        $vendor = $this->approvedVendor($manager);
        $outsider = User::factory()->create();

        try {
            app(ThirdPartyRiskManager::class)->review($vendor, $outsider, ThirdPartyRiskReviewOutcome::Satisfactory, [
                'summary' => 'Unauthorized direct service call.', 'next_review_at' => now()->addMonth(),
            ]);
            $this->fail('The review service must enforce current governance permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('vendor_risk_reviews', 0);
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

    private function acceptedEvidence(User $auditManager, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $auditManager->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id, 'created_by_id' => $auditManager->id, 'assigned_to_id' => $auditManager->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $auditManager->id, 'requestee_id' => $auditManager->id,
        ]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => basename($path), 'file_path' => $path, 'file_size' => strlen($contents),
            'description' => 'Governed third-party review evidence', 'uploaded_by' => $auditManager->id,
        ]);
    }
}
