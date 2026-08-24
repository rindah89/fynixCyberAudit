<?php

namespace Tests\Feature;

use App\Filament\Resources\BusinessServiceResource\Pages\ViewBusinessService;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\ContinuityActivationsRelationManager;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessService;
use App\Models\ContinuityActivation;
use App\Models\ContinuityActivationEvent;
use App\Models\RecoveryPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class ContinuityActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.resilience', true);
    }

    public function test_manager_activates_approved_plan_and_records_forward_recovery_evidence(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        [$manager, $service, $plan] = $this->context();
        Sanctum::actingAs($manager);

        $id = $this->postJson("/api/recovery-plans/{$plan->id}/continuity-activations", [
            'disruption_summary' => 'Primary payment region unavailable.', 'business_impact' => 'Customer checkout is unavailable.',
            'started_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'activated')->json('data.id');

        $this->postJson("/api/continuity-activations/{$id}/events", ['status' => 'recovering', 'summary' => 'Warm standby recovery procedure started.'])
            ->assertOk()->assertJsonPath('data.version', 2);
        Carbon::setTestNow('2026-08-24 09:30:00');
        $this->postJson("/api/continuity-activations/{$id}/events", ['status' => 'restored', 'summary' => 'Customer payment processing restored.', 'actual_recovery_point_minutes' => 10])
            ->assertOk()->assertJsonPath('data.activation_snapshot.outcome', 'met');
        $this->postJson("/api/continuity-activations/{$id}/events", ['status' => 'closed', 'summary' => 'Continuity recovery formally closed.'])->assertOk();

        $activation = ContinuityActivation::query()->findOrFail($id);
        $this->assertSame(90, $activation->actual_recovery_time_minutes);
        $this->assertSame('closed', $activation->status->value);
        $this->assertSame(4, $activation->events()->count());
        $event = $activation->events()->latest('version')->firstOrFail();
        $payload = $event->only(['continuity_activation_id', 'version', 'from_status', 'to_status', 'summary', 'activation_snapshot', 'recorded_by']);
        $payload['from_status'] = $event->from_status?->value;
        $payload['to_status'] = $event->to_status->value;
        $payload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
        $this->assertSame($plan->recovery_procedure, $event->activation_snapshot['plan_snapshot']['recovery_procedure']);
    }

    public function test_activation_scope_lifecycle_and_server_owned_fields_are_enforced(): void
    {
        [$manager, $service, $plan] = $this->context();
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->postJson("/api/recovery-plans/{$plan->id}/continuity-activations", ['disruption_summary' => 'x', 'business_impact' => 'y', 'started_at' => now()])->assertForbidden();

        Sanctum::actingAs($manager);
        $olderPlan = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id, 'approved_by' => $manager->id, 'version' => 2]);
        $newestPlan = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id, 'approved_by' => $manager->id, 'version' => 3]);
        $this->postJson("/api/recovery-plans/{$olderPlan->id}/continuity-activations", ['disruption_summary' => 'stale', 'business_impact' => 'wrong plan', 'started_at' => now()])
            ->assertUnprocessable()->assertJsonValidationErrors('recovery_plan_id');
        $this->postJson("/api/recovery-plans/{$plan->id}/continuity-activations", ['disruption_summary' => 'x', 'business_impact' => 'y', 'started_at' => now(), 'status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $activation = ContinuityActivation::factory()->create(['recovery_plan_id' => $newestPlan->id, 'business_service_id' => $service->id, 'activated_by' => $manager->id]);
        $this->postJson("/api/continuity-activations/{$activation->id}/events", ['status' => 'closed', 'summary' => 'Skip recovery.'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/recovery-plans/{$newestPlan->id}/continuity-activations", ['disruption_summary' => 'second', 'business_impact' => 'duplicate', 'started_at' => now()])
            ->assertUnprocessable()->assertJsonValidationErrors('business_service_id');
        $this->getJson("/api/business-services/{$service->id}/continuity-activations?per_page=1")->assertOk()->assertJsonPath('per_page', 1);
        Sanctum::actingAs($outsider);
        $this->getJson("/api/business-services/{$service->id}/continuity-activations")->assertForbidden();
    }

    public function test_history_is_immutable_retained_and_visible_to_scoped_operator(): void
    {
        [$manager, $service, $plan] = $this->context();
        $activation = ContinuityActivation::factory()->create(['recovery_plan_id' => $plan->id, 'business_service_id' => $service->id, 'activated_by' => $manager->id]);
        $event = ContinuityActivationEvent::factory()->create(['continuity_activation_id' => $activation->id, 'recorded_by' => $manager->id]);
        try {
            $event->update(['summary' => 'rewrite']);
            $this->fail('Event was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('continuity_activation_events', ['id' => $event->id]);
        }
        $migration = require database_path('migrations/2026_08_24_730000_create_continuity_activation_history.php');
        $migration->down();
        $this->assertDatabaseHas('continuity_activation_events', ['id' => $event->id]);

        Livewire::actingAs($manager)->test(ContinuityActivationsRelationManager::class, ['ownerRecord' => $service, 'pageClass' => ViewBusinessService::class])
            ->assertCanSeeTableRecords([$activation])->assertTableActionVisible('inspect', $activation);
    }

    /** @return array{User, BusinessService, RecoveryPlan} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Resilience');
        $service = BusinessService::factory()->create(['owner_id' => $manager->id]);
        BusinessImpactAnalysis::factory()->approved()->create(['business_service_id' => $service->id, 'analyst_id' => $manager->id, 'approved_by' => $manager->id, 'recovery_time_objective_minutes' => 120, 'recovery_point_objective_minutes' => 15]);
        $plan = RecoveryPlan::factory()->approved()->create(['business_service_id' => $service->id, 'owner_id' => $manager->id, 'approved_by' => $manager->id]);

        return [$manager, $service, $plan];
    }
}
