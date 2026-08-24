<?php

namespace Tests\Feature;

use App\Enums\PrivacyActivityStatus;
use App\Enums\PrivacyAssessmentDecision;
use App\Filament\Resources\PrivacyProcessingActivityResource;
use App\Filament\Resources\PrivacyProcessingActivityResource\Pages\ViewPrivacyProcessingActivity;
use App\Filament\Resources\PrivacyProcessingActivityResource\RelationManagers\AssessmentsRelationManager;
use App\Models\PrivacyActivityVersion;
use App\Models\PrivacyImpactAssessment;
use App\Models\PrivacyProcessingActivity;
use App\Models\User;
use App\Privacy\PrivacyManagementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PrivacyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.privacy_management', true);
    }

    private function activityData(User $owner): array
    {
        return ['name' => 'Customer relationship management', 'owner_id' => $owner->id, 'purpose' => 'Manage customer relationships and contracted services.', 'lawful_basis' => 'Contract and legitimate interests', 'data_subject_categories' => ['Customers', 'Customer contacts'], 'personal_data_categories' => ['Identity', 'Contact details'], 'special_category_data' => false, 'recipient_categories' => ['Account team'], 'systems_and_vendors' => ['CRM platform'], 'processing_locations' => ['Cameroon'], 'cross_border_transfer' => false, 'retention_period' => 'Seven years after contract end', 'security_measures' => 'Role-based access, encryption, and access review.', 'next_review_at' => today()->addYear()->toDateString(), 'change_summary' => 'Register the current processing context.'];
    }

    public function test_governed_activity_versions_and_independent_assessment_are_reconstructible(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Privacy');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Privacy Activities');
        $assessor = User::factory()->create();
        $assessor->givePermissionTo('Assess Privacy');
        $service = app(PrivacyManagementManager::class);
        $activity = $service->register($manager, $this->activityData($owner));
        $this->assertSame(PrivacyActivityStatus::Draft, $activity->status);
        $this->assertSame(1, $activity->versions()->count());
        try {
            $service->assess($owner, $activity, ['necessity_assessment' => 'Needed', 'proportionality_assessment' => 'Bounded', 'risk_summary' => 'Risk', 'mitigations' => [], 'residual_risk' => 'Low', 'decision' => PrivacyAssessmentDecision::Approved->value, 'decision_summary' => 'Self approval', 'next_review_at' => today()->addYear()]);
            $this->fail('Expected independence.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $assessment = $service->assess($assessor, $activity, ['necessity_assessment' => 'The stated service requires the listed data.', 'proportionality_assessment' => 'The categories are limited to the declared purpose.', 'risk_summary' => 'Unauthorized access and excessive retention were assessed.', 'mitigations' => ['Quarterly access review', 'Retention deletion control'], 'residual_risk' => 'Low', 'decision' => PrivacyAssessmentDecision::Approved->value, 'decision_summary' => 'Approve this exact retained activity version.', 'next_review_at' => today()->addYear()->toDateString()]);
        $this->assertSame($activity->versions()->first()->id, $assessment->activity_version_id);
        $service->revise($manager, $activity->refresh(), ['status' => PrivacyActivityStatus::Active->value, 'change_summary' => 'Activate after independent approval.']);
        $this->assertSame(PrivacyActivityStatus::Active, $activity->fresh()->status);
        $payload = ['privacy_processing_activity_id' => $assessment->privacy_processing_activity_id, 'version' => $assessment->version, 'activity_version_id' => $assessment->activity_version_id, 'activity_snapshot' => $assessment->activity_snapshot, 'necessity_assessment' => $assessment->necessity_assessment, 'proportionality_assessment' => $assessment->proportionality_assessment, 'risk_summary' => $assessment->risk_summary, 'mitigations' => $assessment->mitigations, 'residual_risk' => $assessment->residual_risk, 'decision' => $assessment->decision->value, 'decision_summary' => $assessment->decision_summary, 'next_review_at' => $assessment->next_review_at->toDateString(), 'assessed_by' => $assessment->assessed_by, 'assessed_at' => $assessment->assessed_at->toIso8601String()];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $assessment->fingerprint);
        $service->revise($owner, $activity->refresh(), ['purpose' => 'Manage customer relationships for the expanded service.', 'change_summary' => 'Material purpose change requires reassessment.']);
        $this->assertSame(PrivacyActivityStatus::AssessmentRequired, $activity->fresh()->status);
    }

    public function test_rest_and_operator_history_are_scoped_paginated_and_server_owned(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Privacy');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Privacy Activities');
        $assessor = User::factory()->create();
        $assessor->givePermissionTo('Assess Privacy');
        $outsider = User::factory()->create();
        $this->actingAs($manager)->postJson('/api/privacy-processing-activities', $this->activityData($owner) + ['number' => 'CALLER', 'status' => 'Active'])->assertUnprocessable()->assertJsonValidationErrors(['number', 'status']);
        $id = $this->actingAs($manager)->postJson('/api/privacy-processing-activities', $this->activityData($owner))->assertCreated()->json('data.id');
        $activity = PrivacyProcessingActivity::findOrFail($id);
        $this->actingAs($owner)->getJson('/api/privacy-processing-activities')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($outsider)->getJson('/api/privacy-processing-activities')->assertForbidden();
        $this->actingAs($manager)->getJson("/api/privacy-processing-activities/{$id}/versions?per_page=1")->assertOk()->assertJsonPath('per_page', 1);
        $this->actingAs($assessor)->postJson("/api/privacy-processing-activities/{$id}/assessments", ['necessity_assessment' => 'Needed', 'proportionality_assessment' => 'Bounded', 'risk_summary' => 'Assessed', 'mitigations' => ['Access control'], 'residual_risk' => 'Medium', 'decision' => 'Mitigation Required', 'decision_summary' => 'Further mitigation is required.', 'next_review_at' => today()->addMonth()->toDateString(), 'fingerprint' => 'caller'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $assessment = $this->actingAs($assessor)->postJson("/api/privacy-processing-activities/{$id}/assessments", ['necessity_assessment' => 'Needed', 'proportionality_assessment' => 'Bounded', 'risk_summary' => 'Assessed', 'mitigations' => ['Access control'], 'residual_risk' => 'Medium', 'decision' => 'Mitigation Required', 'decision_summary' => 'Further mitigation is required.', 'next_review_at' => today()->addMonth()->toDateString()])->assertCreated()->json('data.id');
        Livewire::actingAs($manager);
        Livewire::test(AssessmentsRelationManager::class, ['ownerRecord' => $activity, 'pageClass' => ViewPrivacyProcessingActivity::class])->assertCanSeeTableRecords([PrivacyImpactAssessment::findOrFail($assessment)])->assertTableActionVisible('inspect', PrivacyImpactAssessment::findOrFail($assessment));
    }

    public function test_bounds_immutability_factories_migration_and_module_boundary_are_coherent(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Privacy');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Privacy Activities');
        $activity = PrivacyProcessingActivity::factory()->create(['owner_id' => $owner->id]);
        $version = PrivacyActivityVersion::factory()->create(['privacy_processing_activity_id' => $activity->id, 'recorded_by' => $manager->id]);
        $assessment = PrivacyImpactAssessment::factory()->create(['privacy_processing_activity_id' => $activity->id, 'activity_version_id' => $version->id, 'assessed_by' => $manager->id]);
        $this->assertSame($version->activity_snapshot, $assessment->activity_snapshot);
        $versionPayload = ['privacy_processing_activity_id' => $version->privacy_processing_activity_id, 'version' => $version->version, 'activity_snapshot' => $version->activity_snapshot, 'change_summary' => $version->change_summary, 'recorded_by' => $version->recorded_by, 'recorded_at' => $version->recorded_at->toIso8601String()];
        $this->assertSame(hash('sha256', json_encode($versionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $version->fingerprint);
        $assessmentPayload = ['privacy_processing_activity_id' => $assessment->privacy_processing_activity_id, 'version' => $assessment->version, 'activity_version_id' => $assessment->activity_version_id, 'activity_snapshot' => $assessment->activity_snapshot, 'necessity_assessment' => $assessment->necessity_assessment, 'proportionality_assessment' => $assessment->proportionality_assessment, 'risk_summary' => $assessment->risk_summary, 'mitigations' => $assessment->mitigations, 'residual_risk' => $assessment->residual_risk, 'decision' => $assessment->decision->value, 'decision_summary' => $assessment->decision_summary, 'next_review_at' => $assessment->next_review_at->toDateString(), 'assessed_by' => $assessment->assessed_by, 'assessed_at' => $assessment->assessed_at->toIso8601String()];
        $this->assertSame(hash('sha256', json_encode($assessmentPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $assessment->fingerprint);
        try {
            $assessment->delete();
            $this->fail('Expected append-only evidence.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        foreach (range(2, 100) as $n) {
            PrivacyActivityVersion::factory()->create(['privacy_processing_activity_id' => $activity->id, 'recorded_by' => $manager->id, 'version' => $n]);
        }
        try {
            app(PrivacyManagementManager::class)->revise($manager, $activity, ['purpose' => 'Changed', 'change_summary' => 'Version 101']);
            $this->fail('Expected bound.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activity', $e->errors());
        }
        foreach (range(2, 100) as $n) {
            PrivacyImpactAssessment::factory()->create(['privacy_processing_activity_id' => $activity->id, 'activity_version_id' => $version->id, 'assessed_by' => $manager->id, 'version' => $n]);
        }
        $assessor = User::factory()->create();
        $assessor->givePermissionTo('Assess Privacy');
        try {
            app(PrivacyManagementManager::class)->assess($assessor, $activity, ['necessity_assessment' => 'Bounded', 'proportionality_assessment' => 'Bounded', 'risk_summary' => 'Bounded', 'mitigations' => ['Bounded control'], 'residual_risk' => 'Low', 'decision' => PrivacyAssessmentDecision::Rejected->value, 'decision_summary' => 'Assessment 101.', 'next_review_at' => today()->addYear()]);
            $this->fail('Expected assessment bound.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assessment', $e->errors());
        }
        $migration = require database_path('migrations/2026_08_24_660000_create_governed_privacy_management.php');
        $migration->down();
        $this->assertDatabaseHas('privacy_impact_assessments', ['id' => $assessment->id, 'fingerprint' => $assessment->fingerprint]);
        Config::set('enterprise.modules.privacy_management', false);
        $this->actingAs($manager)->getJson('/api/privacy-processing-activities')->assertForbidden();
        $this->assertFalse(PrivacyProcessingActivityResource::shouldRegisterNavigation());
    }
}
