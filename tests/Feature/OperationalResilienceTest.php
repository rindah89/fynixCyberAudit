<?php

namespace Tests\Feature;

use App\Filament\Resources\BusinessServiceResource;
use App\Models\Application;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessService;
use App\Models\RecoveryExercise;
use App\Models\RecoveryPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.resilience', true);
    }

    public function test_resilience_manager_can_create_service_and_approved_impact_analysis(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);

        $serviceId = $this->postJson('/api/business-services', [
            'code' => 'SVC-PAYMENTS',
            'name' => 'Customer payments',
            'owner_id' => $manager->id,
            'criticality' => 'critical',
            'description' => 'Accept and settle customer payments.',
        ])->assertCreated()->assertJsonPath('data.readiness_status', 'impact_analysis_required')->json('data.id');

        $this->postJson("/api/business-services/{$serviceId}/impact-analyses", [
            'maximum_tolerable_downtime_minutes' => 240,
            'recovery_time_objective_minutes' => 120,
            'recovery_point_objective_minutes' => 15,
            'operational_impact' => 'critical',
            'financial_impact_per_hour' => '25000.00',
            'rationale' => 'Payments fund daily operations.',
            'approve' => true,
        ])->assertCreated()
            ->assertJsonPath('data.approved_by', $manager->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('service.readiness_status', 'recovery_plan_required');

        $this->assertDatabaseHas('business_impact_analyses', [
            'business_service_id' => $serviceId,
            'recovery_time_objective_minutes' => 120,
            'recovery_point_objective_minutes' => 15,
            'approved_by' => $manager->id,
        ]);
    }

    public function test_dependency_must_reference_exactly_one_supported_target(): void
    {
        $manager = $this->manager();
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        $application = Application::factory()->create();
        Sanctum::actingAs($manager);

        $this->postJson("/api/business-services/{$service->id}/dependencies", [
            'dependency_type' => 'technology',
            'criticality' => 'high',
            'application_id' => $application->id,
            'dependent_service_id' => $service->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('dependency');

        $this->postJson("/api/business-services/{$service->id}/dependencies", [
            'dependency_type' => 'technology',
            'criticality' => 'high',
            'application_id' => $application->id,
            'notes' => 'Payment gateway application.',
        ])->assertCreated()->assertJsonPath('data.target_label', $application->name);
    }

    public function test_approved_plan_and_exercise_measure_recovery_objectives(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $manager = $this->manager();
        $service = $this->serviceWithApprovedBia($manager, 120, 15);
        Sanctum::actingAs($manager);

        $planId = $this->postJson("/api/business-services/{$service->id}/recovery-plans", [
            'title' => 'Payments recovery plan',
            'owner_id' => $manager->id,
            'strategy' => 'Fail over to the warm standby region.',
            'activation_criteria' => 'Primary region unavailable for 15 minutes.',
            'recovery_procedure' => 'Authorize failover, restore queues, validate settlement.',
            'communication_plan' => 'Notify incident lead and finance operations.',
            'review_due_at' => '2027-02-23',
            'approve' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');

        $exerciseId = $this->postJson("/api/recovery-plans/{$planId}/exercises", [
            'scenario' => 'Loss of the primary payment region',
            'scheduled_at' => now(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/recovery-exercises/{$exerciseId}/complete", [
            'actual_recovery_time_minutes' => 90,
            'actual_recovery_point_minutes' => 10,
            'observations' => 'Standby processing and settlement validation succeeded.',
            'evidence_reference' => 'EXERCISE-PAY-2026-08',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'passed')
            ->assertJsonPath('service.readiness_status', 'ready')
            ->assertJsonPath('service.latest_exercise_outcome', 'passed');

        $this->assertDatabaseCount('resilience_issues', 0);
    }

    public function test_plan_cannot_be_approved_without_an_approved_impact_analysis(): void
    {
        $manager = $this->manager();
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/business-services/{$service->id}/recovery-plans", [
            'title' => 'Premature plan', 'owner_id' => $manager->id,
            'strategy' => 'Strategy', 'activation_criteria' => 'Criteria',
            'recovery_procedure' => 'Procedure', 'communication_plan' => 'Communications',
            'review_due_at' => now()->addYear(), 'approve' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('approve');
    }

    public function test_module_must_be_enabled_for_resilience_interfaces(): void
    {
        $manager = $this->manager();
        Config::set('enterprise.modules.resilience', false);
        Sanctum::actingAs($manager);

        $this->postJson('/api/business-services', [
            'code' => 'SVC-DISABLED', 'name' => 'Disabled', 'owner_id' => $manager->id, 'criticality' => 'high',
        ])->assertForbidden();
    }

    public function test_failed_exercise_opens_issue_with_objective_snapshot(): void
    {
        $manager = $this->manager();
        $service = $this->serviceWithApprovedBia($manager, 60, 5);
        $plan = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id]);
        $exercise = RecoveryExercise::factory()->create(['recovery_plan_id' => $plan->id, 'facilitator_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/recovery-exercises/{$exercise->id}/complete", [
            'actual_recovery_time_minutes' => 95,
            'actual_recovery_point_minutes' => 12,
            'observations' => 'Database restore and DNS cutover exceeded both objectives.',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.rto_objective_minutes', 60)
            ->assertJsonPath('data.rpo_objective_minutes', 5)
            ->assertJsonPath('data.completed_by', $manager->id)
            ->assertJsonPath('data.issue.status', 'open');

        $this->assertDatabaseHas('resilience_issues', [
            'recovery_exercise_id' => $exercise->id,
            'owner_id' => $manager->id,
            'status' => 'open',
        ]);
    }

    public function test_completed_exercise_is_immutable_and_cannot_be_completed_twice(): void
    {
        $manager = $this->manager();
        $service = $this->serviceWithApprovedBia($manager, 60, 5);
        $plan = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id]);
        $exercise = RecoveryExercise::factory()->create(['recovery_plan_id' => $plan->id, 'facilitator_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $payload = ['actual_recovery_time_minutes' => 50, 'actual_recovery_point_minutes' => 4, 'observations' => 'Completed.'];
        $this->postJson("/api/recovery-exercises/{$exercise->id}/complete", $payload)->assertOk();
        $this->postJson("/api/recovery-exercises/{$exercise->id}/complete", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('recovery_exercise_id');

        $this->expectException(\LogicException::class);
        $exercise->refresh()->update(['observations' => 'Rewrite attempt.']);
    }

    public function test_versions_are_allocated_monotonically_and_cannot_be_supplied(): void
    {
        $manager = $this->manager();
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        Sanctum::actingAs($manager);
        $payload = [
            'maximum_tolerable_downtime_minutes' => 120, 'recovery_time_objective_minutes' => 60,
            'recovery_point_objective_minutes' => 5, 'operational_impact' => 'high', 'rationale' => 'Versioned analysis.',
        ];

        $this->postJson("/api/business-services/{$service->id}/impact-analyses", $payload + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson("/api/business-services/{$service->id}/impact-analyses", $payload)->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson("/api/business-services/{$service->id}/impact-analyses", $payload)->assertCreated()->assertJsonPath('data.version', 2);
    }

    public function test_approved_or_exercised_recovery_plan_cannot_be_deleted(): void
    {
        $manager = $this->manager();
        $service = $this->serviceWithApprovedBia($manager, 60, 5);
        $approved = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id]);

        $this->expectException(\LogicException::class);
        $approved->delete();
    }

    public function test_non_manager_cannot_change_resilience_governance(): void
    {
        $manager = $this->manager();
        $outsider = User::factory()->create();
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/business-services/{$service->id}/impact-analyses", [
            'version' => 1,
            'maximum_tolerable_downtime_minutes' => 120,
            'recovery_time_objective_minutes' => 60,
            'recovery_point_objective_minutes' => 5,
            'operational_impact' => 'high',
            'rationale' => 'Unauthorized attempt.',
        ])->assertForbidden();
    }

    public function test_service_owner_can_discover_but_not_edit_resilience_workspace(): void
    {
        $owner = User::factory()->create();
        $service = BusinessService::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->get(BusinessServiceResource::getUrl('index'))->assertOk();
        $this->get(BusinessServiceResource::getUrl('view', ['record' => $service]))->assertOk();
        $this->get(BusinessServiceResource::getUrl('edit', ['record' => $service]))->assertForbidden();
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Resilience');

        return $user;
    }

    private function serviceWithApprovedBia(User $manager, int $rto, int $rpo): BusinessService
    {
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        BusinessImpactAnalysis::factory()->approved()->create([
            'business_service_id' => $service->id,
            'analyst_id' => $manager->id,
            'approved_by' => $manager->id,
            'maximum_tolerable_downtime_minutes' => $rto * 2,
            'recovery_time_objective_minutes' => $rto,
            'recovery_point_objective_minutes' => $rpo,
        ]);

        return $service;
    }
}
