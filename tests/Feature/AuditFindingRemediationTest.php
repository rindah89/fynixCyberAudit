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
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\RemediationTask;
use App\Models\User;
use App\Remediation\Remediation;
use App\Services\AuditFindingManager;
use App\Services\AuditFindingRemediationManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('private');
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
        $reviewer->givePermissionTo('Read Audits');
        $attachment = $this->acceptedEvidence($reviewer, 'finding-follow-up/reperformance.txt', 'reperformance evidence bytes');

        $followUp = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, [
            'outcome' => 'effective',
            'summary' => 'Reperformance confirmed that quarterly access reviews are complete and operating.',
            'evidence_reference' => 'DR-2026-104 accepted response',
            'evidence_attachment_ids' => [$attachment->id],
        ]);

        $this->assertSame(1, $followUp->version);
        $this->assertSame('effective', $followUp->outcome->value);
        $this->assertSame($reviewer->id, $followUp->reviewed_by);
        $this->assertSame('Completed', $followUp->task_snapshot['status']);
        $this->assertSame($handoff->fingerprint, $followUp->handoff_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', json_encode($followUp->fingerprintPayload(), JSON_THROW_ON_ERROR)), $followUp->fingerprint);
        $this->assertSame($attachment->id, $followUp->evidence->first()->file_attachment_id);
        $this->assertSame(hash('sha256', 'reperformance evidence bytes'), $followUp->evidence->first()->sha256);
        Storage::disk('private')->put($attachment->file_path, 'later source replacement');
        $this->actingAs($reviewer, 'web')->get(route('audit-finding-follow-up-evidence.download', $followUp->evidence->first()))
            ->assertSuccessful()->assertStreamedContent('reperformance evidence bytes');
        $reviewer->revokePermissionTo('Read Audits');
        $this->actingAs($reviewer, 'web')->get(route('audit-finding-follow-up-evidence.download', $followUp->evidence->first()))
            ->assertForbidden();
        $this->actingAs(User::factory()->create(), 'web')->get(route('audit-finding-follow-up-evidence.download', $followUp->evidence->first()))
            ->assertForbidden();
        try {
            $attachment->delete();
            $this->fail('A governed follow-up source attachment was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('file_attachments', ['id' => $attachment->id]);
        }
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

        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Unsupported effective conclusion.']);
            $this->fail('An effective conclusion was recorded without governed evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_attachment_ids', $exception->errors());
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
        $evidence = $this->acceptedEvidence($reviewer, 'finding-follow-up/rework.txt', 'rework evidence');
        $second = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Reperformance now passes.', 'evidence_attachment_ids' => [$evidence->id]]);
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
        $attachment = $this->acceptedEvidence($reviewer, 'finding-follow-up/rest.txt', 'REST evidence');

        $this->actingAs($reviewer)->postJson("/api/audit-finding-remediations/{$handoff['id']}/follow-ups", [
            'outcome' => 'effective',
            'summary' => 'Independent reperformance confirms the corrective action operates.',
            'version' => 99,
        ])->assertUnprocessable();
        $this->actingAs($reviewer)->postJson("/api/audit-finding-remediations/{$handoff['id']}/follow-ups", [
            'outcome' => 'effective',
            'summary' => 'Independent reperformance confirms the corrective action operates.',
            'evidence_attachment_ids' => [$attachment->id],
        ])->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.evidence.0.file_attachment_id', $attachment->id)
            ->assertJsonPath('data.evidence.0.sha256', hash('sha256', 'REST evidence'));

        $this->actingAs($manager)->getJson("/api/audit-findings/{$finding->id}")
            ->assertOk()
            ->assertJsonPath('data.remediation.id', $handoff['id'])
            ->assertJsonPath('data.remediation.follow_ups.0.outcome', 'effective')
            ->assertJsonPath('data.remediation.follow_ups.0.evidence_manifest', [])
            ->assertJsonCount(0, 'data.remediation.follow_ups.0.evidence');
    }

    public function test_mixed_unauthorized_evidence_selection_rolls_back_follow_up_and_retained_copies(): void
    {
        [$finding, $manager] = $this->respondedFinding();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Finding corrective action']);
        $handoff = app(AuditFindingRemediationManager::class)->handoff($finding, $manager, $project, []);
        app(Remediation::class)->updateTaskStatus($manager, $handoff->task, 'Completed');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');
        $authorized = $this->acceptedEvidence($reviewer, 'finding-follow-up/authorized.txt', 'authorized bytes');
        $foreign = $this->acceptedEvidence(User::factory()->create(), 'finding-follow-up/foreign.txt', 'foreign bytes');

        try {
            app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, [
                'outcome' => 'effective', 'summary' => 'Mixed evidence must reject.',
                'evidence_attachment_ids' => [$authorized->id, $foreign->id],
            ]);
            $this->fail('Unauthorized evidence was retained for a follow-up.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_attachment_ids.1', $exception->errors());
        }

        $this->assertDatabaseCount('audit_finding_follow_ups', 0);
        $this->assertDatabaseCount('audit_finding_follow_up_evidence', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('governed-evidence/audit-finding-follow-up'));
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
        $attachment = $this->acceptedEvidence($reviewer, 'finding-follow-up/operator.txt', 'operator evidence');
        $followUp = app(AuditFindingRemediationManager::class)->followUp($handoff, $reviewer, ['outcome' => 'effective', 'summary' => 'Independent reperformance passes.', 'evidence_attachment_ids' => [$attachment->id]]);

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
        $evidenceMigration = require database_path('migrations/2026_08_24_440000_create_audit_finding_follow_up_evidence.php');
        $evidenceMigration->up();
        $evidenceMigration->down();
        $this->assertDatabaseHas('audit_finding_follow_up_evidence', ['id' => $followUp->evidence->first()->id]);
        $this->assertNotEmpty($followUp->fresh()->evidence_manifest);
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

    private function acceptedEvidence(User $auditManager, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $auditManager->id]);
        $request = DataRequest::factory()->create(['audit_id' => $audit->id, 'created_by_id' => $auditManager->id, 'assigned_to_id' => $auditManager->id]);
        $response = DataRequestResponse::factory()->accepted()->create(['data_request_id' => $request->id, 'requester_id' => $auditManager->id, 'requestee_id' => $auditManager->id]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => basename($path), 'file_path' => $path, 'file_size' => strlen($contents),
            'description' => 'Governed audit-finding follow-up evidence', 'uploaded_by' => $auditManager->id,
        ]);
    }
}
