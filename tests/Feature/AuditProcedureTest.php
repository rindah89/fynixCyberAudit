<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditProcedureExporter;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\ProceduresRelationManager;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\AuditProcedureExecution;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use App\Services\AuditProcedureManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditProcedureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_defines_and_assignee_executes_immutable_procedure_snapshot(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));

        $this->assertSame(1, $procedure->version);
        $this->assertSame('planned', $procedure->status);
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution());

        $this->assertSame('completed', $procedure->fresh()->status);
        $this->assertSame($procedure->id, data_get($execution->procedure_snapshot, 'procedure.id'));
        $this->assertSame($item->id, data_get($execution->procedure_snapshot, 'audit_item.id'));
        $this->assertSame(WorkflowStatus::INPROGRESS->value, data_get($execution->procedure_snapshot, 'audit_item.status'));
        $this->assertSame(Effectiveness::UNKNOWN->value, data_get($execution->procedure_snapshot, 'audit_item.effectiveness'));
        $this->assertSame(Applicability::APPLICABLE->value, data_get($execution->procedure_snapshot, 'audit_item.applicability'));
        $this->assertSame($execution->fingerprint, hash('sha256', json_encode([
            'outcome' => $execution->outcome->value, 'result' => $execution->result, 'exceptions' => $execution->exceptions,
            'sample_tested' => $execution->sample_tested, 'evidence_reference' => $execution->evidence_reference,
            'procedure_snapshot' => $execution->procedure_snapshot, 'executed_by' => $execution->executed_by,
            'executed_at' => $execution->executed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $this->expectException(LogicException::class);
        $execution->update(['result' => 'Rewritten result']);
    }

    public function test_assignment_scope_sampling_and_direct_service_authorization_are_enforced(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $outsider = User::factory()->create();
        try {
            app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $outsider));
            $this->fail('An outsider was assigned governed fieldwork.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assigned_to', $exception->errors());
        }
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        try {
            app(AuditProcedureManager::class)->execute($procedure, $outsider, $this->execution());
            $this->fail('An outsider executed governed fieldwork.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(ValidationException::class);
        app(AuditProcedureManager::class)->execute($procedure, $assignee, array_merge($this->execution(), ['sample_tested' => 11]));
    }

    public function test_rest_owns_versions_actor_snapshot_and_fingerprint(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $manager->givePermissionTo(['Read Audits', 'Update Audits']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/audits/{$audit->id}/procedures", $this->definition($item, $assignee) + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $procedureId = $this->postJson("/api/audits/{$audit->id}/procedures", $this->definition($item, $assignee))
            ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        Sanctum::actingAs($assignee);
        $this->postJson("/api/audit-procedures/{$procedureId}/execute", $this->execution() + ['fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->postJson("/api/audit-procedures/{$procedureId}/execute", $this->execution())
            ->assertCreated()->assertJsonPath('data.executed_by', $assignee->id);
        Sanctum::actingAs($manager);
        $this->getJson("/api/audits/{$audit->id}/procedures")->assertOk()->assertJsonPath('data.0.id', $procedureId);
        $this->getJson("/api/audits/{$audit->id}/procedures?per_page=0")->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_closeout_requires_and_snapshots_every_defined_procedure_execution(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $item->update(['status' => WorkflowStatus::COMPLETED, 'auditor_notes' => 'Concluded from the governed work program.', 'effectiveness' => Effectiveness::PARTIAL]);
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $closeout = [
            'opinion' => 'needs_improvement', 'executive_summary' => 'The procedure identified two exceptions.',
            'scope_limitations' => null, 'significant_matters' => 'Two access reviews lacked timestamps.',
            'recommendations_summary' => 'Enforce attributable review completion.',
        ];
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $closeout);
            $this->fail('An unexecuted procedure was omitted from closeout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_procedures', $exception->errors());
        }

        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution());
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $closeout);
        $this->assertSame($procedure->id, data_get($submission->audit_procedure_snapshots, '0.id'));
        $this->assertSame($execution->fingerprint, data_get($submission->audit_procedure_snapshots, '0.execution.fingerprint'));
    }

    public function test_operator_history_export_factories_and_migration_preserve_complete_evidence(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $manager->givePermissionTo('Read Audits');
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution());

        $this->actingAs($manager, 'web');
        Livewire::test(ProceduresRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$procedure])->assertTableActionVisible('inspect', $procedure);
        $this->view('filament.audit-procedure', ['procedure' => $procedure->load(['assignee', 'execution.executor'])])
            ->assertSee($procedure->objective)->assertSee($execution->result)->assertSee($execution->fingerprint);
        $columns = collect(AuditProcedureExporter::getColumns())->map->getName();
        $this->assertContains('execution.procedure_snapshot', $columns);
        $this->assertContains('execution.fingerprint', $columns);

        $factoryExecution = AuditProcedureExecution::factory()->create();
        $this->assertSame('completed', $factoryExecution->procedure->status);
        $this->assertSame(WorkflowStatus::INPROGRESS, $factoryExecution->procedure->audit->status);
        $this->assertSame(WorkflowStatus::INPROGRESS, $factoryExecution->procedure->auditItem->status);
        $this->assertSame($factoryExecution->procedure->audit->manager_id, $factoryExecution->procedure->created_by);
        $this->assertSame($factoryExecution->procedure->assigned_to, $factoryExecution->executed_by);
        $this->assertSame($factoryExecution->fingerprint, hash('sha256', json_encode([
            'outcome' => $factoryExecution->outcome->value, 'result' => $factoryExecution->result,
            'exceptions' => $factoryExecution->exceptions, 'sample_tested' => $factoryExecution->sample_tested,
            'evidence_reference' => $factoryExecution->evidence_reference, 'procedure_snapshot' => $factoryExecution->procedure_snapshot,
            'executed_by' => $factoryExecution->executed_by, 'executed_at' => $factoryExecution->executed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $migration = require database_path('migrations/2026_08_24_390000_create_audit_procedure_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_procedure_executions', ['id' => $factoryExecution->id]);
    }

    private function auditContext(): array
    {
        $baseline = AuditEngagementBaseline::factory()->create();
        $audit = $baseline->audit;
        $audit->update(['status' => WorkflowStatus::INPROGRESS]);
        $manager = $audit->manager;
        $assignee = User::factory()->create();
        $audit->members()->attach($assignee);
        $item = AuditItem::factory()->for($audit)->create([
            'status' => WorkflowStatus::INPROGRESS, 'effectiveness' => Effectiveness::UNKNOWN,
            'applicability' => Applicability::APPLICABLE,
        ]);

        return [$audit, $manager, $assignee, $item];
    }

    private function definition(AuditItem $item, User $assignee): array
    {
        return [
            'audit_item_id' => $item->id, 'code' => 'AP-001', 'title' => 'Inspect quarterly access reviews',
            'objective' => 'Determine whether access reviews are complete and attributable.',
            'steps' => 'Select the defined sample, inspect reviewer evidence, record exceptions, and conclude.',
            'method' => 'inspection', 'population_description' => 'Quarterly access reviews in the audit period.',
            'planned_sample_size' => 10, 'assigned_to' => $assignee->id, 'due_at' => $item->audit->start_date->toDateString(),
        ];
    }

    private function execution(): array
    {
        return [
            'outcome' => 'needs_improvement', 'result' => 'Eight samples met criteria and two lacked reviewer timestamps.',
            'exceptions' => 'Two samples lacked attributable completion timestamps.', 'sample_tested' => 10,
            'evidence_reference' => 'Accepted data request DR-001.',
        ];
    }
}
