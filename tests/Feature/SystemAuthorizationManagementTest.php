<?php

namespace Tests\Feature;

use App\Enums\SystemAuthorizationDecision;
use App\Enums\SystemAuthorizationMonitoringOutcome;
use App\Filament\Resources\SystemAuthorizationPackageResource;
use App\Filament\Resources\SystemAuthorizationPackageResource\Pages\ViewSystemAuthorizationPackage;
use App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers\DecisionsRelationManager;
use App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers\MonitoringReviewsRelationManager;
use App\Models\Application;
use App\Models\Control;
use App\Models\Risk;
use App\Models\SystemAuthorizationDecisionRecord;
use App\Models\SystemAuthorizationMonitoringReview;
use App\Models\SystemAuthorizationPackage;
use App\Models\User;
use App\SystemAuthorization\SystemAuthorizationManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SystemAuthorizationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.system_authorization', true);
    }

    private function packageData(Control $control, Risk $risk): array
    {
        return ['system_boundary' => 'Customer payments application, production data stores, and managed interfaces.', 'impact_level' => 'High', 'data_classifications' => ['Confidential', 'Personal data'], 'control_ids' => [$control->id], 'risk_ids' => [$risk->id], 'open_findings' => ['Encryption key rotation exception remains tracked in POA&M-42.'], 'monitoring_strategy' => 'Quarterly control review, annual package resubmission, and deliberate review after material change.', 'review_frequency_days' => 90, 'poam_reference' => 'POA&M-42', 'change_summary' => 'Submit the initial authorization baseline.'];
    }

    private function decisionData(string $decision = SystemAuthorizationDecision::Authorized->value): array
    {
        return ['decision' => $decision, 'conditions' => $decision === SystemAuthorizationDecision::AuthorizedWithConditions->value ? ['Close POA&M-42 within 90 days'] : [], 'rationale' => 'The retained package supports this deliberate authorization judgment.', 'valid_until' => in_array($decision, [SystemAuthorizationDecision::Authorized->value, SystemAuthorizationDecision::AuthorizedWithConditions->value], true) ? today()->addYear()->toDateString() : null];
    }

    public function test_package_snapshots_current_context_and_independent_authorizer_decides_exact_version(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage System Authorizations', 'Read Applications', 'Read Controls', 'Read Risks']);
        $owner = User::factory()->create();
        $application = Application::factory()->create(['owner_id' => $owner->id]);
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        $authorizer = User::factory()->create();
        $authorizer->givePermissionTo('Authorize Systems');
        $service = app(SystemAuthorizationManager::class);
        $package = $service->submit($manager, $application, $this->packageData($control, $risk));
        $this->assertSame(1, $package->version);
        $this->assertSame($application->id, $package->application_snapshot['id']);
        $this->assertSame($control->id, $package->control_snapshot[0]['id']);
        $this->assertSame($risk->id, $package->risk_snapshot[0]['id']);
        $this->assertSame('pending_review', $package->authorization_state);
        $packagePayload = ['application_id' => $package->application_id, 'version' => $package->version, 'application_snapshot' => $package->application_snapshot, 'system_boundary' => $package->system_boundary, 'impact_level' => $package->impact_level, 'data_classifications' => $package->data_classifications, 'control_snapshot' => $package->control_snapshot, 'risk_snapshot' => $package->risk_snapshot, 'open_findings' => $package->open_findings, 'monitoring_strategy' => $package->monitoring_strategy, 'review_frequency_days' => $package->review_frequency_days, 'poam_reference' => $package->poam_reference, 'change_summary' => $package->change_summary, 'submitted_by' => $package->submitted_by, 'submitted_at' => $package->submitted_at->toIso8601String()];
        $this->assertSame(hash('sha256', json_encode($packagePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $package->fingerprint);
        foreach ([$manager, $owner] as $excluded) {
            try {
                $service->decide($excluded, $package, $this->decisionData());
                $this->fail('Expected independent authorization.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $decision = $service->decide($authorizer, $package, $this->decisionData(SystemAuthorizationDecision::AuthorizedWithConditions->value));
        $payload = ['system_authorization_package_id' => $decision->system_authorization_package_id, 'version' => $decision->version, 'package_snapshot' => $decision->package_snapshot, 'decision' => $decision->decision->value, 'conditions' => $decision->conditions, 'rationale' => $decision->rationale, 'decided_by' => $decision->decided_by, 'decided_at' => $decision->decided_at->toIso8601String(), 'valid_until' => $decision->valid_until->toDateString()];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $decision->fingerprint);
        $this->assertSame('authorized_with_conditions', $package->fresh()->authorization_state);
        $revoked = $service->decide($authorizer, $package, $this->decisionData(SystemAuthorizationDecision::Revoked->value));
        $this->assertSame(SystemAuthorizationDecision::Revoked, $revoked->decision);
        $this->assertSame('revoked', $package->fresh()->authorization_state);
        $restricted = User::factory()->create();
        $restricted->givePermissionTo(['Manage System Authorizations', 'Read Applications', 'Read Risks']);
        try {
            $service->submit($restricted, $application, $this->packageData($control, $risk));
            $this->fail('Expected exact selected-record authorization.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $staleApp = Application::factory()->create();
        $stale = $service->submit($manager, $staleApp, $this->packageData($control, $risk));
        $staleApp->update(['description' => 'Materially changed after package submission.']);
        try {
            $service->decide($authorizer, $stale, $this->decisionData());
            $this->fail('Expected stale package rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package', $e->errors());
        }
        $replacement = $service->submit($manager, $staleApp->refresh(), $this->packageData($control, $risk) + ['change_summary' => 'Replace the stale package.']);
        try {
            $service->decide($authorizer, $stale, $this->decisionData());
            $this->fail('Expected superseded package rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package', $e->errors());
        }
        $this->assertSame(SystemAuthorizationDecision::Authorized, $service->decide($authorizer, $replacement, $this->decisionData())->decision);
    }

    public function test_rest_scope_validation_history_and_operator_inspection_are_governed(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage System Authorizations', 'Read Applications', 'Read Controls', 'Read Risks']);
        $owner = User::factory()->create();
        $application = Application::factory()->create(['owner_id' => $owner->id]);
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        $authorizer = User::factory()->create();
        $authorizer->givePermissionTo('Authorize Systems');
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read System Authorizations');
        $outsider = User::factory()->create();
        $this->actingAs($manager)->postJson("/api/applications/{$application->id}/authorization-packages", $this->packageData($control, $risk) + ['fingerprint' => 'caller'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $id = $this->actingAs($manager)->postJson("/api/applications/{$application->id}/authorization-packages", $this->packageData($control, $risk))->assertCreated()->json('data.id');
        $this->actingAs($outsider)->getJson('/api/system-authorization-packages')->assertForbidden();
        $this->actingAs($reader)->getJson('/api/system-authorization-packages?per_page=1')->assertOk()->assertJsonPath('per_page', 1);
        $decisionId = $this->actingAs($authorizer)->postJson("/api/system-authorization-packages/{$id}/decisions", $this->decisionData())->assertCreated()->json('data.id');
        $this->actingAs($reader)->getJson("/api/system-authorization-packages/{$id}")->assertOk()->assertJsonPath('data.decisions.0.id', $decisionId);
        $package = SystemAuthorizationPackage::findOrFail($id);
        Livewire::actingAs($reader);
        Livewire::test(DecisionsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewSystemAuthorizationPackage::class])->assertCanSeeTableRecords([SystemAuthorizationDecisionRecord::findOrFail($decisionId)])->assertTableActionVisible('inspect', SystemAuthorizationDecisionRecord::findOrFail($decisionId));
        Config::set('enterprise.modules.system_authorization', false);
        $this->actingAs($reader)->getJson('/api/system-authorization-packages')->assertForbidden();
        $this->assertFalse(SystemAuthorizationPackageResource::shouldRegisterNavigation());
    }

    public function test_bounds_append_only_expiry_and_retained_migration_are_enforced(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage System Authorizations', 'Read Applications', 'Read Controls', 'Read Risks']);
        $application = Application::factory()->create();
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        $authorizer = User::factory()->create();
        $authorizer->givePermissionTo('Authorize Systems');
        $service = app(SystemAuthorizationManager::class);
        $package = $service->submit($manager, $application, $this->packageData($control, $risk));
        $decision = $service->decide($authorizer, $package, $this->decisionData());
        try {
            $package->update(['system_boundary' => 'rewrite']);
            $this->fail('Expected append-only evidence.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->travelTo(today()->addYear()->addDay()->startOfDay());
        $this->assertSame('authorization_expired', $package->fresh()->authorization_state);
        $this->travelBack();
        foreach (range(2, 100) as $version) {
            SystemAuthorizationPackage::query()->create(['application_id' => $application->id, 'version' => $version, 'application_snapshot' => $package->application_snapshot, 'system_boundary' => 'Boundary', 'impact_level' => 'High', 'data_classifications' => [], 'control_snapshot' => [], 'risk_snapshot' => [], 'open_findings' => [], 'monitoring_strategy' => 'Strategy', 'change_summary' => 'Version', 'submitted_by' => $manager->id, 'submitted_at' => now(), 'fingerprint' => hash('sha256', 'package-'.$version)]);
        }
        try {
            $service->submit($manager, $application, $this->packageData($control, $risk));
            $this->fail('Expected package bound.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('application', $e->errors());
        }
        $factoryPackage = SystemAuthorizationPackage::factory()->create();
        $factoryDecision = SystemAuthorizationDecisionRecord::factory()->create(['system_authorization_package_id' => $factoryPackage->id]);
        $factoryPayload = ['system_authorization_package_id' => $factoryDecision->system_authorization_package_id, 'version' => $factoryDecision->version, 'package_snapshot' => $factoryDecision->package_snapshot, 'decision' => $factoryDecision->decision->value, 'conditions' => $factoryDecision->conditions, 'rationale' => $factoryDecision->rationale, 'decided_by' => $factoryDecision->decided_by, 'decided_at' => $factoryDecision->decided_at->toIso8601String(), 'valid_until' => $factoryDecision->valid_until->toDateString()];
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factoryDecision->fingerprint);
        $factoryReview = SystemAuthorizationMonitoringReview::factory()->create();
        $factoryReviewPayload = ['system_authorization_package_id' => $factoryReview->system_authorization_package_id, 'version' => $factoryReview->version, 'package_snapshot' => $factoryReview->package_snapshot, 'decision_snapshot' => $factoryReview->decision_snapshot, 'metrics' => $factoryReview->metrics, 'findings' => $factoryReview->findings, 'outcome' => $factoryReview->outcome->value, 'required_actions' => $factoryReview->required_actions, 'summary' => $factoryReview->summary, 'reviewed_by' => $factoryReview->reviewed_by, 'reviewed_at' => $factoryReview->reviewed_at->toIso8601String(), 'next_review_at' => $factoryReview->next_review_at->toDateString()];
        $this->assertSame(hash('sha256', json_encode($factoryReviewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factoryReview->fingerprint);
        $migration = require database_path('migrations/2026_08_24_680000_create_system_authorization_management.php');
        $migration->down();
        $this->assertDatabaseHas('system_authorization_decisions', ['id' => $decision->id, 'fingerprint' => $decision->fingerprint]);
    }

    public function test_independent_monitoring_binds_current_authorization_and_derives_due_and_action_state(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage System Authorizations', 'Read Applications', 'Read Controls', 'Read Risks']);
        $application = Application::factory()->create();
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        $authorizer = User::factory()->create();
        $authorizer->givePermissionTo('Authorize Systems');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Monitor System Authorizations');
        $service = app(SystemAuthorizationManager::class);
        $package = $service->submit($manager, $application, $this->packageData($control, $risk));
        $decision = $service->decide($authorizer, $package, $this->decisionData());
        $data = ['metrics' => ['Control tests reviewed: 12', 'Open POA&M items: 1'], 'findings' => [], 'outcome' => SystemAuthorizationMonitoringOutcome::Effective->value, 'required_actions' => [], 'summary' => 'The deliberate review found the retained authorization context operating as represented.'];
        foreach ([$manager, $authorizer] as $excluded) {
            try {
                $service->monitor($excluded, $package, $data);
                $this->fail('Expected independent monitoring.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $review = $this->actingAs($reviewer)->postJson("/api/system-authorization-packages/{$package->id}/monitoring-reviews", $data)->assertCreated()->json('data');
        $record = SystemAuthorizationMonitoringReview::findOrFail($review['id']);
        $payload = ['system_authorization_package_id' => $record->system_authorization_package_id, 'version' => $record->version, 'package_snapshot' => $record->package_snapshot, 'decision_snapshot' => $record->decision_snapshot, 'metrics' => $record->metrics, 'findings' => $record->findings, 'outcome' => $record->outcome->value, 'required_actions' => $record->required_actions, 'summary' => $record->summary, 'reviewed_by' => $record->reviewed_by, 'reviewed_at' => $record->reviewed_at->toIso8601String(), 'next_review_at' => $record->next_review_at->toDateString()];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $record->fingerprint);
        $this->assertSame($decision->fingerprint, $record->decision_snapshot['fingerprint']);
        $this->assertSame('monitoring_current', $package->fresh()->monitoring_state);
        Livewire::actingAs($reviewer);
        Livewire::test(MonitoringReviewsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewSystemAuthorizationPackage::class])->assertCanSeeTableRecords([$record])->assertTableActionVisible('inspect', $record);
        try {
            $record->update(['summary' => 'rewrite']);
            $this->fail('Expected immutable monitoring evidence.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $application->update(['description' => 'Material authorization context drift.']);
        try {
            $service->monitor($reviewer, $package, $data);
            $this->fail('Expected stale effective rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outcome', $e->errors());
        }
        $adverse = array_merge($data, ['outcome' => SystemAuthorizationMonitoringOutcome::NeedsAction->value, 'findings' => ['Application context changed after authorization.'], 'required_actions' => ['Submit a replacement authorization package.']]);
        $service->monitor($reviewer, $package, $adverse);
        $this->assertSame('action_required', $package->fresh()->monitoring_state);
        $this->actingAs($reviewer)->getJson("/api/system-authorization-packages/{$package->id}/monitoring-reviews?per_page=1")->assertOk()->assertJsonPath('total', 2);
        foreach (range(3, 100) as $version) {
            SystemAuthorizationMonitoringReview::factory()->create(['system_authorization_package_id' => $package->id, 'version' => $version, 'package_snapshot' => $record->package_snapshot, 'decision_snapshot' => $record->decision_snapshot, 'reviewed_by' => $reviewer->id]);
        }
        try {
            $service->monitor($reviewer, $package, $adverse);
            $this->fail('Expected monitoring history bound.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package', $e->errors());
        }
        $migration = require database_path('migrations/2026_08_24_690000_create_system_authorization_monitoring.php');
        $migration->down();
        $this->assertDatabaseHas('system_authorization_monitoring_reviews', ['id' => $record->id, 'fingerprint' => $record->fingerprint]);
    }
}
