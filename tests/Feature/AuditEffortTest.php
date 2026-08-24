<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditEffortBudgetExporter;
use App\Filament\Exports\AuditTimeEntryExporter;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\EffortBudgetsRelationManager;
use App\Filament\Resources\AuditResource\RelationManagers\TimeEntriesRelationManager;
use App\Models\AuditEffortBudget;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\AuditTimeEntry;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use App\Services\AuditEffortManager;
use App\Services\AuditProcedureManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditEffortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_versions_budget_member_records_time_and_reversal_updates_summary(): void
    {
        [$audit, $manager, $member, $procedure] = $this->context();
        $first = app(AuditEffortManager::class)->budget($audit, $manager, $this->budgetPayload($procedure, $member, 600));
        $second = app(AuditEffortManager::class)->budget($audit, $manager, $this->budgetPayload($procedure, $member, 720));
        $entry = app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 300));

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($second->fingerprint, $this->budgetFingerprint($second));
        $this->assertSame($entry->fingerprint, $this->entryFingerprint($entry));
        $this->assertSame(['planned_minutes' => 720, 'actual_minutes' => 300, 'variance_minutes' => 420], array_intersect_key(app(AuditEffortManager::class)->summary($audit), array_flip(['planned_minutes', 'actual_minutes', 'variance_minutes'])));

        $reversal = app(AuditEffortManager::class)->reverse($entry, $member, ['reason' => 'Entered against the wrong work date.']);
        $this->assertSame($entry->id, $reversal->reverses_time_entry_id);
        $this->assertSame(0, app(AuditEffortManager::class)->summary($audit)['actual_minutes']);
        try {
            app(AuditEffortManager::class)->reverse($entry, $member, ['reason' => 'Duplicate reversal.']);
            $this->fail('A time entry was reversed twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry', $exception->errors());
        }
        $this->expectException(LogicException::class);
        $entry->update(['minutes' => 1]);
    }

    public function test_effort_authorization_team_scope_dates_and_daily_bound_are_enforced(): void
    {
        [$audit, $manager, $member, $procedure] = $this->context();
        $outsider = User::factory()->create();
        try {
            app(AuditEffortManager::class)->budget($audit, $outsider, $this->budgetPayload($procedure, $member, 60));
            $this->fail('An outsider set the budget.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(AuditEffortManager::class)->record($audit, $outsider, $this->entryPayload($audit, null, 60));
            $this->fail('An outsider recorded time.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $entry = app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 1));
        try {
            app(AuditEffortManager::class)->reverse($entry, $outsider, ['reason' => 'Unauthorized']);
            $this->fail('An outsider reversed a time entry.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 1439));
        $this->expectException(ValidationException::class);
        app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 1));
    }

    public function test_reversal_respects_the_history_bound_including_reversals(): void
    {
        [$audit, $manager, $member, $procedure] = $this->context();
        $entry = app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 1));
        $template = $entry->getAttributes();
        unset($template['id']);
        foreach (array_chunk(range(1, 9999), 40) as $chunk) {
            DB::table('audit_time_entries')->insert(array_map(fn (): array => $template, $chunk));
        }

        try {
            app(AuditEffortManager::class)->reverse($entry, $manager, ['reason' => 'Correction']);
            $this->fail('A reversal exceeded the governed history bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit', $exception->errors());
        }
        $this->assertDatabaseCount('audit_time_entries', 10000);
    }

    public function test_rest_prohibits_server_fields_and_exposes_paginated_history_and_summary(): void
    {
        [$audit, $manager, $member, $procedure] = $this->context();
        $manager->givePermissionTo(['Read Audits', 'Update Audits']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/audits/{$audit->id}/effort-budgets", $this->budgetPayload($procedure, $member, 600) + ['version' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson("/api/audits/{$audit->id}/effort-budgets", $this->budgetPayload($procedure, $member, 600))->assertCreated()->assertJsonPath('data.version', 1);
        Sanctum::actingAs($member);
        $entryId = $this->postJson("/api/audits/{$audit->id}/time-entries", $this->entryPayload($audit, $procedure, 120))
            ->assertCreated()->assertJsonPath('data.user_id', $member->id)->json('data.id');
        $this->postJson("/api/audit-time-entries/{$entryId}/reverse", ['reason' => 'Correction'])->assertCreated()->assertJsonPath('data.reverses_time_entry_id', $entryId);
        Sanctum::actingAs($manager);
        $this->getJson("/api/audits/{$audit->id}/effort-budgets?per_page=0")->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson("/api/audits/{$audit->id}/time-entries")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/audits/{$audit->id}/effort-summary")->assertOk()->assertJsonPath('data.planned_minutes', 600)->assertJsonPath('data.actual_minutes', 0);
    }

    public function test_closeout_retains_complete_effort_history_and_summary(): void
    {
        [$audit, $manager, $member, $procedure, $item] = $this->context();
        $budget = app(AuditEffortManager::class)->budget($audit, $manager, $this->budgetPayload($procedure, $member, 600));
        $entry = app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 180));
        app(AuditProcedureManager::class)->execute($procedure, $member, [
            'outcome' => 'effective', 'result' => 'The selected population met the procedure criteria.', 'sample_tested' => 10,
        ]);
        $item->update(['status' => WorkflowStatus::COMPLETED, 'auditor_notes' => 'Concluded from procedure results.', 'effectiveness' => Effectiveness::EFFECTIVE]);
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, [
            'opinion' => 'satisfactory', 'executive_summary' => 'The work program supports the conclusion.', 'scope_limitations' => null,
            'significant_matters' => 'None.', 'recommendations_summary' => 'Continue the control.',
        ]);

        $this->assertSame($budget->fingerprint, data_get($submission->audit_effort_snapshots, 'budgets.0.fingerprint'));
        $this->assertSame($entry->fingerprint, data_get($submission->audit_effort_snapshots, 'time_entries.0.fingerprint'));
        $this->assertSame(180, data_get($submission->audit_effort_snapshots, 'summary.actual_minutes'));
    }

    public function test_operator_history_exports_factories_and_migration_preserve_effort_evidence(): void
    {
        [$audit, $manager, $member, $procedure] = $this->context();
        $manager->givePermissionTo('Read Audits');
        $budget = app(AuditEffortManager::class)->budget($audit, $manager, $this->budgetPayload($procedure, $member, 600));
        $entry = app(AuditEffortManager::class)->record($audit, $member, $this->entryPayload($audit, $procedure, 120));

        $this->actingAs($manager, 'web');
        Livewire::test(EffortBudgetsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$budget])->assertTableActionVisible('inspect', $budget);
        Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$entry])->assertTableActionVisible('inspect', $entry);
        $this->view('filament.audit-effort-budget', ['budget' => $budget->load('setter')])->assertSee($budget->fingerprint)->assertSee($budget->rationale);
        $this->view('filament.audit-time-entry', ['entry' => $entry->load('entrant')])->assertSee($entry->fingerprint)->assertSee($entry->activity);
        $this->assertContains('allocation_snapshot', collect(AuditEffortBudgetExporter::getColumns())->map->getName());
        $this->assertContains('procedure_snapshot', collect(AuditTimeEntryExporter::getColumns())->map->getName());

        $factoryBudget = AuditEffortBudget::factory()->create();
        $factoryEntry = AuditTimeEntry::factory()->create();
        $this->assertSame($factoryBudget->audit_procedure_id, data_get($factoryBudget->allocation_snapshot, 'procedure.id'));
        $this->assertSame($factoryEntry->audit_procedure_id, data_get($factoryEntry->procedure_snapshot, 'id'));
        $this->assertSame($factoryBudget->fingerprint, $this->budgetFingerprint($factoryBudget));
        $this->assertSame($factoryEntry->fingerprint, $this->entryFingerprint($factoryEntry));
        $migration = require database_path('migrations/2026_08_24_400000_create_audit_effort_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_time_entries', ['id' => $factoryEntry->id]);
    }

    private function context(): array
    {
        $baseline = AuditEngagementBaseline::factory()->create();
        $audit = $baseline->audit;
        $audit->update(['status' => WorkflowStatus::INPROGRESS]);
        $manager = $audit->manager;
        $member = User::factory()->create();
        $audit->members()->attach($member);
        $item = AuditItem::factory()->for($audit)->create(['status' => WorkflowStatus::INPROGRESS, 'effectiveness' => Effectiveness::UNKNOWN, 'applicability' => Applicability::APPLICABLE]);
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, [
            'audit_item_id' => $item->id, 'code' => 'AP-EFFORT', 'title' => 'Inspect approvals', 'objective' => 'Confirm approvals.',
            'steps' => 'Inspect the selected population.', 'method' => 'inspection', 'planned_sample_size' => 10,
            'assigned_to' => $member->id, 'due_at' => $audit->start_date->toDateString(),
        ]);

        return [$audit, $manager, $member, $procedure, $item];
    }

    private function budgetPayload($procedure, User $user, int $minutes): array
    {
        return ['audit_procedure_id' => $procedure?->id, 'user_id' => $user->id, 'planned_minutes' => $minutes, 'rationale' => 'Allocate effort to complete the governed procedure.'];
    }

    private function entryPayload($audit, $procedure, int $minutes): array
    {
        return ['audit_procedure_id' => $procedure?->id, 'work_date' => $audit->start_date->toDateString(), 'minutes' => $minutes, 'activity' => 'Executed procedure steps.', 'notes' => 'Reviewed the selected population.'];
    }

    private function budgetFingerprint(AuditEffortBudget $budget): string
    {
        return hash('sha256', json_encode([
            'audit_id' => $budget->audit_id, 'audit_procedure_id' => $budget->audit_procedure_id, 'user_id' => $budget->user_id,
            'version' => $budget->version, 'planned_minutes' => $budget->planned_minutes, 'rationale' => $budget->rationale,
            'allocation_snapshot' => $budget->allocation_snapshot, 'set_by' => $budget->set_by, 'set_at' => $budget->set_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function entryFingerprint(AuditTimeEntry $entry): string
    {
        return hash('sha256', json_encode([
            'audit_id' => $entry->audit_id, 'audit_procedure_id' => $entry->audit_procedure_id, 'user_id' => $entry->user_id,
            'entry_type' => $entry->entry_type->value, 'reverses_time_entry_id' => $entry->reverses_time_entry_id,
            'work_date' => $entry->work_date->toDateString(), 'minutes' => $entry->minutes, 'activity' => $entry->activity,
            'notes' => $entry->notes, 'source_reference' => $entry->source_reference, 'budget_snapshot' => $entry->budget_snapshot,
            'procedure_snapshot' => $entry->procedure_snapshot, 'entered_by' => $entry->entered_by, 'entered_at' => $entry->entered_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
