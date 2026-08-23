<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlTestDefinitionResource;
use App\Models\Control;
use App\Models\ControlTestDefinition;
use App\Models\ControlTestExecution;
use App\Models\Implementation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContinuousControlTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_owner_can_define_and_execute_a_recurring_numeric_test(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Controls');
        $control = Control::factory()->create(['control_owner_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $definitionId = $this->postJson("/api/controls/{$control->id}/test-definitions", [
            'code' => 'CCT-MFA-01',
            'name' => 'MFA coverage',
            'owner_id' => $owner->id,
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '98',
            'frequency' => 'monthly',
            'next_run_at' => '2026-08-31 12:00:00',
        ])->assertCreated()
            ->assertJsonPath('data.monitoring_status', 'scheduled')
            ->json('data.id');

        $this->postJson("/api/control-test-definitions/{$definitionId}/execute", [
            'observed_value' => '99.2',
            'notes' => 'Identity provider coverage export reviewed.',
            'evidence_reference' => 'IDP-MFA-2026-08',
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'passed')
            ->assertJsonPath('definition.last_outcome', 'passed')
            ->assertJsonPath('definition.next_run_at', '2026-09-23T12:00:00.000000Z');

        $this->assertDatabaseHas('control_test_executions', [
            'control_test_definition_id' => $definitionId,
            'executed_by' => $owner->id,
            'observed_value' => '99.2',
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '98',
            'outcome' => 'passed',
        ]);
        $this->assertDatabaseCount('control_test_findings', 0);
    }

    public function test_failed_threshold_opens_a_finding_and_preserves_run_history(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, [
            'metric_type' => 'boolean',
            'operator' => 'equals',
            'expected_value' => 'true',
        ]);
        Sanctum::actingAs($owner);

        $firstId = $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => 'false',
            'notes' => 'Backup restoration failed.',
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.finding.status', 'open')
            ->json('data.id');

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => 'true',
            'notes' => 'Retest succeeded after correction.',
        ])->assertCreated()->assertJsonPath('data.outcome', 'passed');

        $this->assertDatabaseCount('control_test_executions', 2);
        $this->assertDatabaseHas('control_test_findings', ['control_test_execution_id' => $firstId, 'status' => 'open']);
    }

    public function test_execution_result_is_derived_and_cannot_be_supplied_or_rewritten(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '5',
            'outcome' => 'passed',
        ])->assertUnprocessable()->assertJsonValidationErrors('outcome');

        $execution = ControlTestExecution::factory()->create([
            'control_test_definition_id' => $definition->id,
            'executed_by' => $owner->id,
            'observed_value' => '5',
            'outcome' => 'failed',
            'result_reason' => 'Observed value did not meet the threshold.',
            'executed_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $execution->update(['outcome' => 'passed']);
    }

    public function test_only_owner_control_owner_or_control_manager_can_execute(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $definition = $this->definition($owner);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '10',
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '10',
        ])->assertCreated();
    }

    public function test_definition_rejects_an_implementation_not_mapped_to_the_control(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Controls');
        $control = Control::factory()->create(['control_owner_id' => $manager->id]);
        $implementation = Implementation::factory()->create();
        Sanctum::actingAs($manager);

        $this->postJson("/api/controls/{$control->id}/test-definitions", [
            'code' => 'CCT-MAP-01',
            'name' => 'Mapped implementation test',
            'owner_id' => $manager->id,
            'implementation_id' => $implementation->id,
            'metric_type' => 'numeric',
            'operator' => 'equals',
            'expected_value' => '1',
            'frequency' => 'monthly',
            'next_run_at' => now()->addMonth(),
        ])->assertUnprocessable()->assertJsonValidationErrors('implementation_id');
    }

    public function test_definition_rejects_an_invalid_threshold_for_its_metric_type(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Controls');
        $control = Control::factory()->create(['control_owner_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/controls/{$control->id}/test-definitions", [
            'code' => 'CCT-INVALID-01',
            'name' => 'Invalid boolean definition',
            'owner_id' => $manager->id,
            'metric_type' => 'boolean',
            'operator' => 'greater_than',
            'expected_value' => 'true',
            'frequency' => 'monthly',
            'next_run_at' => now()->addMonth(),
        ])->assertUnprocessable()->assertJsonValidationErrors('operator');
    }

    public function test_one_time_definition_is_completed_after_its_execution(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, ['frequency' => 'one_time']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '10',
        ])->assertCreated()->assertJsonPath('definition.monitoring_status', 'completed');

        $this->assertNull($definition->refresh()->next_run_at);

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '10',
        ])->assertUnprocessable()->assertJsonValidationErrors('control_test_definition_id');
    }

    public function test_large_decimal_values_are_compared_without_float_precision_loss(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, [
            'operator' => 'equals',
            'expected_value' => '900719925474099',
        ]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '900719925474098',
        ])->assertCreated()->assertJsonPath('data.outcome', 'failed');
    }

    public function test_boolean_tests_reject_undocumented_truthy_aliases(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, [
            'metric_type' => 'boolean',
            'operator' => 'equals',
            'expected_value' => 'true',
        ]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => 'yes',
        ])->assertUnprocessable()->assertJsonValidationErrors('observed_value');
    }

    public function test_execution_preserves_the_threshold_snapshot_after_definition_edits(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Controls');
        $definition = $this->definition($manager, ['expected_value' => '10']);
        Sanctum::actingAs($manager);

        $executionId = $this->postJson("/api/control-test-definitions/{$definition->id}/execute", [
            'observed_value' => '11',
        ])->assertCreated()->json('data.id');

        $definition->update(['operator' => 'less_than', 'expected_value' => '5']);

        $this->assertDatabaseHas('control_test_executions', [
            'id' => $executionId,
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '10',
        ]);
    }

    public function test_definition_owner_without_control_update_permission_cannot_edit_governance(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner);

        $this->actingAs($owner)
            ->get(ControlTestDefinitionResource::getUrl('edit', ['record' => $definition]))
            ->assertForbidden();
    }

    public function test_inactive_or_not_yet_due_definition_status_and_execution_rules(): void
    {
        $owner = User::factory()->create();
        $future = $this->definition($owner, ['next_run_at' => now()->addDay()]);
        $inactive = $this->definition($owner, ['code' => 'CCT-INACTIVE', 'is_active' => false]);
        Sanctum::actingAs($owner);

        $this->assertSame('scheduled', $future->monitoring_status);
        $this->assertSame('inactive', $inactive->monitoring_status);

        $this->postJson("/api/control-test-definitions/{$inactive->id}/execute", [
            'observed_value' => '10',
        ])->assertUnprocessable()->assertJsonValidationErrors('control_test_definition_id');
    }

    public function test_owner_can_open_the_control_testing_workspace(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner);

        $this->actingAs($owner)->get(ControlTestDefinitionResource::getUrl('index'))->assertOk();
        $this->get(ControlTestDefinitionResource::getUrl('view', ['record' => $definition]))->assertOk();
    }

    private function definition(User $owner, array $attributes = []): ControlTestDefinition
    {
        $control = Control::factory()->create(['control_owner_id' => $owner->id]);

        return ControlTestDefinition::factory()->create(array_merge([
            'control_id' => $control->id,
            'owner_id' => $owner->id,
            'code' => 'CCT-'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => 'Control test',
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '10',
            'frequency' => 'monthly',
            'next_run_at' => now(),
            'is_active' => true,
        ], $attributes));
    }
}
