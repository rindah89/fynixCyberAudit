<?php

namespace Tests\Feature;

use App\Enums\AuditManagementPosition;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditFindingExporter;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\GovernedFindingsRelationManager;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\AuditFindingFollowUp;
use App\Models\AuditItem;
use App\Models\RemediationTask;
use App\Models\User;
use App\Remediation\Remediation;
use App\Services\AuditFindingManager;
use App\Services\AuditFindingRemediationManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditFindingRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
    }

    public function test_authorized_manager_hands_agreed_finding_to_remediation_with_immutable_evidence(): void
    {
        [$finding, $manager, $owner] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);

        $handoff = app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, [
            'priority' => 'High',
            'assignee_id' => $owner->id,
        ]);

        $this->assertSame($finding->id, $handoff->audit_finding_id);
        $this->assertSame($finding->latestResponse->id, $handoff->audit_management_response_id);
        $this->assertSame($finding->id, $handoff->task->audit_finding_id);
        $this->assertSame($finding->latestResponse->target_date->toDateString(), $handoff->task->due_date->toDateString());
        $this->assertSame($finding->fingerprint, $handoff->finding_snapshot['fingerprint']);
        $this->assertSame($finding->latestResponse->fingerprint, $handoff->response_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', json_encode($handoff->fingerprintPayload(), JSON_THROW_ON_ERROR)), $handoff->fingerprint);
        try {
            app(AuditFindingManager::class)->respond($finding, $owner, [
                'position' => 'disagreed', 'response' => 'Attempted revision after handoff.',
            ]);
            $this->fail('A handed-off management commitment was rewritten by a later response.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('finding', $exception->errors());
        }
        try {
            $handoff->task->update(['audit_finding_id' => null]);
            $this->fail('The governed task source identity was rewritten.');
        } catch (LogicException) {
            $this->assertSame($finding->id, $handoff->task->fresh()->audit_finding_id);
        }
        $this->expectException(LogicException::class);
        $handoff->task->delete();
    }

    public function test_independent_reviewer_records_versioned_effectiveness_follow_up_after_task_completion(): void
    {
        [$finding, $manager, $owner] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);
        $handoff = app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, []);
        app(Remediation::class)->updateTaskStatus($manager, $handoff->task, 'Completed');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');

        $followUp = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, [
            'outcome' => 'effective',
            'summary' => 'Reperformance confirmed that quarterly access reviews are complete and operating.',
            'evidence_reference' => 'DR-2026-104 accepted response',
        ]);

        $this->assertSame(1, $followUp->version);
        $this->assertSame('effective', $followUp->outcome->value);
        $this->assertSame($reviewer->id, $followUp->reviewed_by);
        $this->assertSame('Completed', $followUp->task_snapshot['status']);
        $this->assertSame($handoff->fingerprint, $followUp->handoff_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', json_encode($followUp->fingerprintPayload(), JSON_THROW_ON_ERROR)), $followUp->fingerprint);
        try {
            app(Remediation::class)->updateTaskStatus($manager, $handoff->task->fresh(), 'In Progress');
            $this->fail('A task with final effective follow-up was reopened.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('task', $exception->errors());
        }
        $this->assertSame('Completed', $handoff->task->fresh()->status);
    }

    public function test_handoff_and_follow_up_enforce_current_response_task_and_independence(): void
    {
        [$finding, $manager, $owner] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('Update Audits');

        $this->expectException(HttpException::class);
        app(AuditFindingRemediationManager::class)->handoff($finding, $outsider, $project, []);
    }

    public function test_disagreed_latest_response_cannot_be_handed_to_remediation(): void
    {
        [$finding, $manager, $owner] = $this->respondedFinding();
        app(AuditFindingManager::class)->respond($finding, $owner, [
            'position' => 'disagreed', 'response' => 'Management does not accept the stated condition.',
        ]);
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);

        try {
            app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, []);
            $this->fail('A disagreed latest management position was handed to remediation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('finding', $exception->errors());
        }
        $this->assertDatabaseCount('audit_finding_remediations', 0);
        $this->assertDatabaseCount('remediation_tasks', 0);
    }

    public function test_follow_up_requires_completed_task_independent_reviewer_and_changed_rework(): void
    {
        [$finding, $manager, $owner] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);
        $handoff = app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, []);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');

        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'ineffective', 'summary' => 'Not ready.']);
            $this->fail('Incomplete remediation must not be reviewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('task', $exception->errors());
        }

        app(Remediation::class)->updateTaskStatus($manager, $handoff->task, 'Completed');
        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $owner, ['outcome' => 'ineffective', 'summary' => 'Self review.']);
            $this->fail('Management owners must not verify their own remediation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $taskOwner = User::factory()->create();
        $taskOwner->givePermissionTo('Update Audits');
        $handoff->task->update(['owner_id' => $taskOwner->id]);
        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $taskOwner, ['outcome' => 'ineffective', 'summary' => 'Task-owner self review.']);
            $this->fail('The remediation task owner reviewed their own work.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $first = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'ineffective', 'summary' => 'Two samples still failed.']);
        $this->assertSame(1, $first->version);
        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'No changed task evidence.']);
            $this->fail('A repeat review requires changed task evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('task', $exception->errors());
        }

        app(Remediation::class)->updateTaskStatus($manager, $handoff->task->fresh(), 'In Progress');
        $this->travel(1)->seconds();
        app(Remediation::class)->updateTaskStatus($manager, $handoff->task->fresh(), 'Completed');
        $second = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Reperformance now passes.']);
        $this->assertSame(2, $second->version);

        $this->expectException(ValidationException::class);
        app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Duplicate final review.']);
    }

    public function test_rest_handoff_and_follow_up_are_server_owned_and_exposed_on_finding_detail(): void
    {
        [$finding, $manager] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);

        $this->actingAs($manager)->postJson("/api/audit-findings/{$finding->id}/remediation", [
            'remediation_project_id' => $project->id,
            'fingerprint' => str_repeat('x', 64),
        ])->assertUnprocessable();

        $handoff = $this->actingAs($manager)->postJson("/api/audit-findings/{$finding->id}/remediation", [
            'remediation_project_id' => $project->id,
        ])->assertCreated()->json('data');

        $task = RemediationTask::query()->findOrFail($handoff['remediation_task_id']);
        app(Remediation::class)->updateTaskStatus($manager, $task, 'Completed');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');

        $this->actingAs($reviewer)->postJson("/api/audit-finding-remediations/{$handoff['id']}/follow-ups", [
            'outcome' => 'effective',
            'summary' => 'Independent reperformance confirms the corrective action operates.',
            'version' => 99,
        ])->assertUnprocessable();
        $this->actingAs($reviewer)->postJson("/api/audit-finding-remediations/{$handoff['id']}/follow-ups", [
            'outcome' => 'effective',
            'summary' => 'Independent reperformance confirms the corrective action operates.',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        $this->actingAs($manager)->getJson("/api/audit-findings/{$finding->id}")
            ->assertOk()
            ->assertJsonPath('data.remediation.id', $handoff['id'])
            ->assertJsonPath('data.remediation.follow_ups.0.outcome', 'effective');
    }

    public function test_operator_export_factories_and_migration_expose_remediation_follow_up_evidence(): void
    {
        [$finding, $manager] = $this->respondedFinding();
        $manager->givePermissionTo('Read Audits');
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);
        $handoff = app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, []);
        app(Remediation::class)->updateTaskStatus($manager, $handoff->task, 'Completed');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');
        $followUp = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Independent reperformance passes.']);

        $this->actingAs($manager, 'web');
        Livewire::test(GovernedFindingsRelationManager::class, ['ownerRecord' => $finding->audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$finding])->assertTableActionVisible('inspect', $finding);
        $this->view('filament.audit-finding', ['finding' => $finding->fresh()->load(['accountableOwner', 'raiser', 'responses.respondent', 'remediation.task', 'remediation.handoffActor', 'remediation.followUps.reviewer'])])
            ->assertSee($handoff->task->number)->assertSee($handoff->fingerprint)->assertSee($followUp->summary)->assertSee($followUp->fingerprint);
        $this->assertContains('remediation', collect(AuditFindingExporter::getColumns())->map->getName());

        $factoryFollowUp = AuditFindingFollowUp::factory()->create();
        $this->assertSame('Completed', $factoryFollowUp->remediation->task->status);
        $this->assertSame($factoryFollowUp->remediation->fingerprint, data_get($factoryFollowUp->handoff_snapshot, 'fingerprint'));
        $this->assertSame(hash('sha256', json_encode($factoryFollowUp->fingerprintPayload(), JSON_THROW_ON_ERROR)), $factoryFollowUp->fingerprint);
        $migration = require database_path('migrations/2026_08_24_430000_create_audit_finding_remediation_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_finding_follow_ups', ['id' => $factoryFollowUp->id]);
    }

    /** @return array{AuditFinding, User, User} */
    private function respondedFinding(): array
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $item = AuditItem::factory()->for($audit)->create(['status' => WorkflowStatus::INPROGRESS]);
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, [
            'audit_item_id' => $item->id,
            'title' => 'Privileged access reviews are overdue',
            'severity' => 'high',
            'condition' => 'Quarterly reviews were not completed.',
            'criteria' => 'The access standard requires quarterly review.',
            'effect' => 'Excess access can remain active.',
            'recommendation' => 'Complete and evidence quarterly reviews.',
            'accountable_owner_id' => $owner->id,
        ]);
        app(AuditFindingManager::class)->respond($finding, $owner, [
            'position' => AuditManagementPosition::Agreed->value,
            'response' => 'Management accepts the finding.',
            'action_plan' => 'Complete access reviews and automate reminders.',
            'target_date' => now()->addMonth()->toDateString(),
        ]);

        return [$finding->fresh(['latestResponse']), $manager, $owner];
    }
}
