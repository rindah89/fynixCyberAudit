<?php

namespace Tests\Feature;

use App\Enums\EsgGoalStatus;
use App\Enums\EsgKpiStatus;
use App\Esg\EsgMaterialityManager;
use App\Esg\EsgPerformanceManager;
use App\Filament\Resources\EsgGoalResource\Pages\ViewEsgGoal;
use App\Filament\Resources\EsgGoalResource\RelationManagers\KpisRelationManager;
use App\Filament\Resources\EsgKpiResource\Pages\ViewEsgKpi;
use App\Filament\Resources\EsgKpiResource\RelationManagers\ObservationsRelationManager;
use App\Models\EsgGoal;
use App\Models\EsgKpi;
use App\Models\EsgKpiObservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EsgPerformanceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.esg_management', true);
    }

    public function test_goal_kpi_and_observations_bind_current_materiality_and_derive_target_state(): void
    {
        [$topic, $manager, $owner] = $this->materialTopic();
        $service = app(EsgPerformanceManager::class);
        $goal = $service->createGoal($manager, $topic, $this->goalData($owner));
        $this->assertSame(EsgGoalStatus::Active, $goal->status);
        $this->assertSame($topic->latestAssessment->fingerprint, data_get($goal->assessment_snapshot, 'fingerprint'));
        $this->assertSame($goal->fingerprint, $this->goalFingerprint($goal));

        $kpi = $service->defineKpi($manager, $goal, $this->kpiData($owner));
        $this->assertSame('100.000000', $kpi->baseline_value);
        $this->assertSame('70.000000', $kpi->target_value);
        $this->assertSame($goal->fingerprint, data_get($kpi->goal_snapshot, 'fingerprint'));
        $this->assertSame($kpi->fingerprint, $this->kpiFingerprint($kpi));
        foreach ([fn () => $goal->update(['title' => 'Rewrite']), fn () => $kpi->update(['target_value' => '60'])] as $mutation) {
            try {
                $mutation();
                $this->fail('Governed ESG definition evidence was mutable.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }

        $first = $service->observe($owner, $kpi, ['observed_value' => '85', 'notes' => 'Deliberate reporting-period observation.', 'source_reference' => 'ESG-DATA-001']);
        $this->assertSame(EsgKpiStatus::TargetNotMet, $first->status);
        $this->assertSame(EsgGoalStatus::AtRisk, $goal->fresh()->status);
        $this->assertSame($first->fingerprint, $this->observationFingerprint($first));

        $second = $service->observe($owner, $kpi, ['observed_value' => '70.000000', 'source_reference' => 'ESG-DATA-002']);
        $this->assertSame(EsgKpiStatus::TargetMet, $second->status);
        $this->assertSame(EsgGoalStatus::Achieved, $goal->fresh()->status);
        $this->assertSame('target_met', $kpi->fresh()->monitoring_status);
        $this->assertSame(2, $second->version);
    }

    public function test_performance_rest_is_scoped_server_owned_bounded_and_retained(): void
    {
        [$topic, $manager, $owner] = $this->materialTopic();
        $reader = $this->userWith('Read ESG');
        $kpiOwner = $this->userWith('Own ESG Topics');
        $outsider = User::factory()->create();

        $goalId = $this->actingAs($manager)->postJson("/api/esg-material-topics/{$topic->id}/goals", $this->goalData($owner) + ['code' => 'CALLER'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $goalId = $this->actingAs($manager)->postJson("/api/esg-material-topics/{$topic->id}/goals", $this->goalData($owner))->assertCreated()->json('data.id');
        $goal = EsgGoal::query()->findOrFail($goalId);
        $kpiId = $this->actingAs($manager)->postJson("/api/esg-goals/{$goalId}/kpis", $this->kpiData($kpiOwner) + ['last_status' => 'target_met'])->assertUnprocessable()->assertJsonValidationErrors('last_status');
        $kpiId = $this->actingAs($manager)->postJson("/api/esg-goals/{$goalId}/kpis", $this->kpiData($kpiOwner))->assertCreated()->json('data.id');
        $kpi = EsgKpi::query()->findOrFail($kpiId);
        $observationId = $this->actingAs($kpiOwner)->postJson("/api/esg-kpis/{$kpiId}/observations", ['observed_value' => '90', 'status' => 'target_met'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $observationId = $this->actingAs($kpiOwner)->postJson("/api/esg-kpis/{$kpiId}/observations", ['observed_value' => '90'])->assertCreated()->json('data.id');

        $this->actingAs($reader)->getJson("/api/esg-material-topics/{$topic->id}/goals?per_page=1")->assertOk()->assertJsonPath('data.0.id', $goalId);
        $this->actingAs($reader)->getJson("/api/esg-goals/{$goalId}/kpis?per_page=1")->assertOk()->assertJsonPath('data.0.id', $kpiId);
        $this->actingAs($reader)->getJson("/api/esg-kpis/{$kpiId}/observations?per_page=1")->assertOk()->assertJsonPath('data.0.id', $observationId);
        $this->actingAs($kpiOwner)->getJson("/api/esg-kpis/{$kpiId}")->assertOk()->assertJsonPath('data.id', $kpiId);
        $this->actingAs($outsider)->getJson("/api/esg-kpis/{$kpiId}")->assertForbidden();
        Livewire::actingAs($reader);
        Livewire::test(KpisRelationManager::class, ['ownerRecord' => $goal, 'pageClass' => ViewEsgGoal::class])->assertCanSeeTableRecords([$kpi])->assertTableActionVisible('inspect', $kpi);
        $observation = EsgKpiObservation::query()->findOrFail($observationId);
        Livewire::test(ObservationsRelationManager::class, ['ownerRecord' => $kpi, 'pageClass' => ViewEsgKpi::class])->assertCanSeeTableRecords([$observation])->assertTableActionVisible('inspect', $observation);
        $this->view('filament.esg-performance-evidence', ['title' => 'KPI observation', 'snapshot' => $observation->kpi_snapshot, 'record' => $observation])->assertSee($observation->reason)->assertSee($observation->fingerprint);
        try {
            app(EsgPerformanceManager::class)->observe($outsider, $kpi, ['observed_value' => '80']);
            $this->fail('Unauthorized direct KPI observation succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $observation->update(['notes' => 'Rewrite']);
            $this->fail('Observation evidence was mutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        foreach (range(2, 1000) as $version) {
            EsgKpiObservation::query()->create([
                'esg_kpi_id' => $kpi->id, 'version' => $version, 'kpi_snapshot' => $observation->kpi_snapshot,
                'observed_value' => '90.000000', 'status' => EsgKpiStatus::TargetNotMet,
                'reason' => 'Bound observation.', 'observed_by' => $kpiOwner->id, 'observed_at' => now(),
                'fingerprint' => hash('sha256', "esg-kpi-observation-{$version}"),
            ]);
        }
        try {
            app(EsgPerformanceManager::class)->observe($kpiOwner, $kpi, ['observed_value' => '70']);
            $this->fail('Expected exact observation history bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kpi', $exception->errors());
        }

        foreach (range(2, 100) as $number) {
            EsgKpi::query()->create(array_merge($kpi->only(['esg_goal_id', 'name', 'description', 'owner_id', 'unit', 'direction', 'baseline_value', 'target_value', 'measurement_method', 'source_reference', 'frequency_days', 'next_due_at', 'last_observed_at', 'last_status', 'is_active', 'goal_snapshot', 'created_by', 'governed_at']), ['code' => $goal->code.'-K'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'fingerprint' => hash('sha256', "esg-kpi-{$number}")]));
        }
        try {
            app(EsgPerformanceManager::class)->defineKpi($manager, $goal, $this->kpiData($kpiOwner));
            $this->fail('Expected exact KPI bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('goal', $exception->errors());
        }

        foreach (range(2, 100) as $number) {
            EsgGoal::query()->create(array_merge($goal->only(['esg_material_topic_id', 'title', 'description', 'owner_id', 'status', 'baseline_date', 'target_date', 'topic_snapshot', 'assessment_snapshot', 'created_by', 'governed_at']), ['code' => $topic->code.'-G'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'fingerprint' => hash('sha256', "esg-goal-{$number}")]));
        }
        try {
            app(EsgPerformanceManager::class)->createGoal($manager, $topic, $this->goalData($owner));
            $this->fail('Expected exact goal bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic', $exception->errors());
        }

        $migration = require database_path('migrations/2026_08_24_710000_create_esg_goal_and_kpi_management.php');
        $migration->down();
        $this->assertDatabaseHas('esg_kpi_observations', ['id' => $observationId, 'fingerprint' => $observation->fingerprint]);

        $factoryGoal = EsgGoal::factory()->create();
        $factoryKpi = EsgKpi::factory()->create();
        $factoryObservation = EsgKpiObservation::factory()->create();
        $this->assertSame($factoryGoal->fingerprint, $this->goalFingerprint($factoryGoal));
        $this->assertSame($factoryKpi->fingerprint, $this->kpiFingerprint($factoryKpi));
        $this->assertSame($factoryObservation->fingerprint, $this->observationFingerprint($factoryObservation));
    }

    public function test_goal_requires_current_material_assessment_and_kpi_direction_is_decimal_safe(): void
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $topic = app(EsgMaterialityManager::class)->register($manager, $this->topicData($owner));
        try {
            app(EsgPerformanceManager::class)->createGoal($manager, $topic, $this->goalData($owner));
            $this->fail('Expected material assessment requirement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic', $exception->errors());
        }

        $assessor = $this->userWith('Assess ESG');
        app(EsgMaterialityManager::class)->assess($assessor, $topic, $this->assessmentData());
        $goal = app(EsgPerformanceManager::class)->createGoal($manager, $topic, $this->goalData($owner));
        foreach ([
            ['baseline_value' => '100', 'target_value' => '101', 'direction' => 'decrease'],
            ['baseline_value' => '999999999999999.1', 'target_value' => '1', 'direction' => 'decrease'],
        ] as $invalid) {
            try {
                app(EsgPerformanceManager::class)->defineKpi($manager, $goal, array_merge($this->kpiData($owner), $invalid));
                $this->fail('Expected invalid KPI numeric contract.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    private function materialTopic(): array
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $assessor = $this->userWith('Assess ESG');
        $topic = app(EsgMaterialityManager::class)->register($manager, $this->topicData($owner));
        app(EsgMaterialityManager::class)->assess($assessor, $topic, $this->assessmentData());

        return [$topic->fresh(), $manager, $owner];
    }

    private function topicData(User $owner): array
    {
        return ['name' => 'Operational emissions', 'pillar' => 'Environmental', 'owner_id' => $owner->id, 'description' => 'Material emissions topic.', 'impact_context' => 'Climate impact.', 'risk_context' => 'Transition exposure.', 'opportunity_context' => 'Efficiency.', 'stakeholder_groups' => ['Communities'], 'framework_references' => ['GRI 305'], 'organizational_boundary' => 'Consolidated operations.', 'next_review_at' => today()->addYear()->toDateString(), 'change_summary' => 'Initial topic.'];
    }

    private function assessmentData(): array
    {
        return ['impact_materiality' => 5, 'financial_materiality' => 4, 'stakeholder_evidence' => 'Deliberate stakeholder evidence.', 'methodology' => 'Double-materiality assessment.', 'decision' => 'material', 'decision_summary' => 'Material topic.', 'next_review_at' => today()->addYear()->toDateString()];
    }

    private function goalData(User $owner): array
    {
        return ['title' => 'Reduce operational emissions', 'description' => 'Reduce measured operational emissions against the governed baseline.', 'owner_id' => $owner->id, 'baseline_date' => today()->subYear()->toDateString(), 'target_date' => today()->addYears(3)->toDateString()];
    }

    private function kpiData(User $owner): array
    {
        return ['name' => 'Operational emissions index', 'description' => 'A deliberate normalized emissions index.', 'owner_id' => $owner->id, 'unit' => 'index points', 'direction' => 'decrease', 'baseline_value' => '100', 'target_value' => '70', 'measurement_method' => 'Normalize operator-entered reporting-period value to the governed baseline.', 'source_reference' => 'ESG-METHOD-001', 'frequency_days' => 90];
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    private function goalFingerprint(EsgGoal $goal): string
    {
        return hash('sha256', json_encode(['esg_material_topic_id' => $goal->esg_material_topic_id, 'code' => $goal->code, 'title' => $goal->title, 'description' => $goal->description, 'owner_id' => $goal->owner_id, 'baseline_date' => $goal->baseline_date->toDateString(), 'target_date' => $goal->target_date->toDateString(), 'topic_snapshot' => $goal->topic_snapshot, 'assessment_snapshot' => $goal->assessment_snapshot, 'created_by' => $goal->created_by, 'governed_at' => $goal->governed_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function kpiFingerprint(EsgKpi $kpi): string
    {
        return hash('sha256', json_encode(['esg_goal_id' => $kpi->esg_goal_id, 'code' => $kpi->code, 'name' => $kpi->name, 'description' => $kpi->description, 'owner_id' => $kpi->owner_id, 'unit' => $kpi->unit, 'direction' => $kpi->direction->value, 'baseline_value' => $kpi->baseline_value, 'target_value' => $kpi->target_value, 'measurement_method' => $kpi->measurement_method, 'source_reference' => $kpi->source_reference, 'frequency_days' => $kpi->frequency_days, 'goal_snapshot' => $kpi->goal_snapshot, 'created_by' => $kpi->created_by, 'governed_at' => $kpi->governed_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function observationFingerprint(EsgKpiObservation $observation): string
    {
        return hash('sha256', json_encode(['esg_kpi_id' => $observation->esg_kpi_id, 'version' => $observation->version, 'kpi_snapshot' => $observation->kpi_snapshot, 'observed_value' => $observation->observed_value, 'status' => $observation->status->value, 'reason' => $observation->reason, 'notes' => $observation->notes, 'source_reference' => $observation->source_reference, 'observed_by' => $observation->observed_by, 'observed_at' => $observation->observed_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
