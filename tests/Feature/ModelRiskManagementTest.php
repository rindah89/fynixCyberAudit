<?php

namespace Tests\Feature;

use App\Enums\ModelGovernanceStatus;
use App\Enums\ModelLifecycleStatus;
use App\Enums\ModelValidationDecision;
use App\Filament\Resources\GovernedModelResource;
use App\Filament\Resources\GovernedModelResource\Pages\ViewGovernedModel;
use App\Filament\Resources\GovernedModelResource\RelationManagers\ValidationsRelationManager;
use App\ModelRisk\ModelRiskManager;
use App\Models\GovernedModel;
use App\Models\GovernedModelVersion;
use App\Models\ModelValidationReview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModelRiskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.model_risk_management', true);
    }

    private function modelData(User $owner, User $developer): array
    {
        return ['name' => 'Credit loss forecasting model', 'model_type' => 'Credit', 'tier' => 1, 'owner_id' => $owner->id, 'developer_id' => $developer->id, 'intended_use' => 'Estimate expected credit losses for governed portfolio reporting.', 'methodology' => 'Segmented statistical regression with deliberate expert overlays.', 'input_data' => ['Loan balances', 'Payment history'], 'outputs' => ['Expected loss estimate'], 'assumptions' => ['Historical performance remains relevant'], 'limitations' => ['Performance may degrade under structural change'], 'usage_restrictions' => ['Not approved for individual credit decisions'], 'implementation_reference' => 'MODEL-REPO/credit-loss/v1', 'change_frequency' => 'Quarterly or after material methodology change', 'next_review_at' => today()->addYear()->toDateString(), 'change_summary' => 'Register the complete initial model context.'];
    }

    private function validationData(string $decision = ModelValidationDecision::Approved->value): array
    {
        return ['scope' => 'Independent conceptual soundness and outcome review.', 'testing_performed' => 'Reperformance, sensitivity analysis, and benchmark comparison.', 'findings' => ['No material calculation exception identified'], 'performance_summary' => 'Observed test results remained within deliberately selected tolerances.', 'limitations_assessment' => 'The retained limitations remain relevant and require ongoing monitoring.', 'decision' => $decision, 'conditions' => $decision === ModelValidationDecision::ConditionallyApproved->value ? ['Use only for portfolio reporting'] : [], 'decision_summary' => 'Record the independent validation conclusion for this exact version.', 'valid_until' => today()->addYear()->toDateString()];
    }

    public function test_independent_validation_governs_production_and_material_change_requires_revalidation(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Model Risk');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Governed Models');
        $developer = User::factory()->create();
        $developer->givePermissionTo('Develop Governed Models');
        $validator = User::factory()->create();
        $validator->givePermissionTo('Validate Models');
        $service = app(ModelRiskManager::class);
        $model = $service->register($manager, $this->modelData($owner, $developer));
        $this->assertSame(ModelLifecycleStatus::Proposed, $model->lifecycle_status);
        $this->assertSame(ModelGovernanceStatus::ValidationRequired, $model->governance_status);
        foreach ([$owner, $developer, $manager] as $excluded) {
            try {
                $service->validate($excluded, $model, $this->validationData());
                $this->fail('Expected validator independence.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $review = $service->validate($validator, $model, $this->validationData());
        $this->assertSame(ModelLifecycleStatus::Production, $model->fresh()->lifecycle_status);
        $this->assertSame(ModelGovernanceStatus::Approved, $model->fresh()->governance_status);
        $this->assertSame(ModelGovernanceStatus::Approved->value, $model->fresh()->validation_state);
        $this->assertSame($model->versions()->first()->id, $review->model_version_id);
        $payload = ['governed_model_id' => $review->governed_model_id, 'version' => $review->version, 'model_version_id' => $review->model_version_id, 'model_snapshot' => $review->model_snapshot, 'scope' => $review->scope, 'testing_performed' => $review->testing_performed, 'findings' => $review->findings, 'performance_summary' => $review->performance_summary, 'limitations_assessment' => $review->limitations_assessment, 'decision' => $review->decision->value, 'conditions' => $review->conditions, 'decision_summary' => $review->decision_summary, 'validated_by' => $review->validated_by, 'validated_at' => $review->validated_at->toIso8601String(), 'valid_until' => $review->valid_until->toDateString()];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $review->fingerprint);
        $service->revise($developer, $model->refresh(), ['methodology' => 'Updated segmented regression with an expanded overlay.', 'change_summary' => 'Material methodology change.']);
        $this->assertSame(ModelLifecycleStatus::Development, $model->fresh()->lifecycle_status);
        $this->assertSame(ModelGovernanceStatus::ValidationRequired, $model->fresh()->governance_status);
        $this->assertSame(ModelGovernanceStatus::ValidationRequired->value, $model->fresh()->validation_state);
    }

    public function test_rest_scope_server_fields_conditional_controls_and_terminal_retirement(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Model Risk');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Governed Models');
        $developer = User::factory()->create();
        $developer->givePermissionTo('Develop Governed Models');
        $validator = User::factory()->create();
        $validator->givePermissionTo('Validate Models');
        $outsider = User::factory()->create();
        $this->actingAs($manager)->postJson('/api/governed-models', $this->modelData($owner, $developer) + ['code' => 'CALLER', 'governance_status' => 'Approved'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'governance_status']);
        $id = $this->actingAs($manager)->postJson('/api/governed-models', $this->modelData($owner, $developer))->assertCreated()->json('data.id');
        $model = GovernedModel::findOrFail($id);
        $this->actingAs($owner)->getJson('/api/governed-models')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($outsider)->getJson('/api/governed-models')->assertForbidden();
        $this->actingAs($manager)->getJson("/api/governed-models/{$id}/versions?per_page=1")->assertOk()->assertJsonPath('per_page', 1);
        $conditional = $this->validationData(ModelValidationDecision::ConditionallyApproved->value);
        $this->actingAs($validator)->postJson("/api/governed-models/{$id}/validations", array_merge($conditional, ['conditions' => []]))->assertUnprocessable()->assertJsonValidationErrors('conditions');
        $reviewId = $this->actingAs($validator)->postJson("/api/governed-models/{$id}/validations", $conditional)->assertCreated()->json('data.id');
        $this->assertSame(ModelGovernanceStatus::Restricted, $model->fresh()->governance_status);
        Livewire::actingAs($manager);
        Livewire::test(ValidationsRelationManager::class, ['ownerRecord' => $model->refresh(), 'pageClass' => ViewGovernedModel::class])->assertCanSeeTableRecords([ModelValidationReview::findOrFail($reviewId)])->assertTableActionVisible('inspect', ModelValidationReview::findOrFail($reviewId));
        app(ModelRiskManager::class)->revise($manager, $model->refresh(), array_merge($this->modelData($owner, $developer), ['lifecycle_status' => ModelLifecycleStatus::Retired->value, 'change_summary' => 'Retire the governed model.']));
        try {
            app(ModelRiskManager::class)->revise($manager, $model->refresh(), ['methodology' => 'Rewrite', 'change_summary' => 'Rewrite retired model.']);
            $this->fail('Expected terminal state.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('model', $e->errors());
        }
        Config::set('enterprise.modules.model_risk_management', false);
        $this->actingAs($manager)->getJson('/api/governed-models')->assertForbidden();
        $this->assertFalse(GovernedModelResource::shouldRegisterNavigation());
    }

    public function test_factories_bounds_immutability_and_migration_retention_are_coherent(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Model Risk');
        $owner = User::factory()->create();
        $owner->givePermissionTo('Own Governed Models');
        $developer = User::factory()->create();
        $developer->givePermissionTo('Develop Governed Models');
        $model = GovernedModel::factory()->create(['owner_id' => $owner->id, 'developer_id' => $developer->id]);
        $version = GovernedModelVersion::factory()->create(['governed_model_id' => $model->id, 'recorded_by' => $manager->id]);
        $review = ModelValidationReview::factory()->create(['governed_model_id' => $model->id, 'model_version_id' => $version->id, 'validated_by' => $manager->id]);
        $versionPayload = ['governed_model_id' => $version->governed_model_id, 'version' => $version->version, 'model_snapshot' => $version->model_snapshot, 'change_summary' => $version->change_summary, 'recorded_by' => $version->recorded_by, 'recorded_at' => $version->recorded_at->toIso8601String()];
        $this->assertSame(hash('sha256', json_encode($versionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $version->fingerprint);
        $reviewPayload = ['governed_model_id' => $review->governed_model_id, 'version' => $review->version, 'model_version_id' => $review->model_version_id, 'model_snapshot' => $review->model_snapshot, 'scope' => $review->scope, 'testing_performed' => $review->testing_performed, 'findings' => $review->findings, 'performance_summary' => $review->performance_summary, 'limitations_assessment' => $review->limitations_assessment, 'decision' => $review->decision->value, 'conditions' => $review->conditions, 'decision_summary' => $review->decision_summary, 'validated_by' => $review->validated_by, 'validated_at' => $review->validated_at->toIso8601String(), 'valid_until' => $review->valid_until->toDateString()];
        $this->assertSame(hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $review->fingerprint);
        $expiredModel = GovernedModel::factory()->create(['owner_id' => $owner->id, 'developer_id' => $developer->id, 'lifecycle_status' => ModelLifecycleStatus::Production, 'governance_status' => ModelGovernanceStatus::Approved]);
        $expiredVersion = GovernedModelVersion::factory()->create(['governed_model_id' => $expiredModel->id, 'recorded_by' => $manager->id]);
        ModelValidationReview::factory()->create(['governed_model_id' => $expiredModel->id, 'model_version_id' => $expiredVersion->id, 'validated_by' => $manager->id, 'valid_until' => today()->subDay()]);
        $this->assertSame(ModelGovernanceStatus::ValidationExpired->value, $expiredModel->fresh()->validation_state);
        try {
            $review->update(['decision_summary' => 'Rewrite']);
            $this->fail('Expected append-only validation evidence.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        foreach (range(2, 100) as $number) {
            GovernedModelVersion::factory()->create(['governed_model_id' => $model->id, 'recorded_by' => $manager->id, 'version' => $number]);
        }
        try {
            app(ModelRiskManager::class)->revise($manager, $model, ['methodology' => 'Version 101', 'change_summary' => 'Version 101']);
            $this->fail('Expected version bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('model', $exception->errors());
        }
        foreach (range(2, 100) as $number) {
            ModelValidationReview::factory()->create(['governed_model_id' => $model->id, 'model_version_id' => $version->id, 'validated_by' => $manager->id, 'version' => $number]);
        }
        $validator = User::factory()->create();
        $validator->givePermissionTo('Validate Models');
        try {
            app(ModelRiskManager::class)->validate($validator, $model, $this->validationData());
            $this->fail('Expected validation bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('validation', $exception->errors());
        }
        $migration = require database_path('migrations/2026_08_24_670000_create_governed_model_risk_management.php');
        $migration->down();
        $this->assertDatabaseHas('model_validation_reviews', ['id' => $review->id, 'fingerprint' => $review->fingerprint]);
    }
}
