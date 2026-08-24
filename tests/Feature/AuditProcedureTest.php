<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditProcedureExporter;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\CloseoutSubmissionsRelationManager;
use App\Filament\Resources\AuditResource\RelationManagers\ProceduresRelationManager;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\AuditProcedureExecution;
use App\Models\AuditWorkpaperReview;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use App\Services\AuditProcedureManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('private');
    }

    public function test_manager_defines_and_assignee_executes_immutable_procedure_snapshot(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));

        $this->assertSame(1, $procedure->version);
        $this->assertSame('planned', $procedure->status);
        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/closeout.txt', 'closeout workpaper evidence');
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + ['evidence_attachment_ids' => [$attachment->id]]);

        $this->assertSame('completed', $procedure->fresh()->status);
        $this->assertSame($procedure->id, data_get($execution->procedure_snapshot, 'procedure.id'));
        $this->assertSame($item->id, data_get($execution->procedure_snapshot, 'audit_item.id'));
        $this->assertSame(WorkflowStatus::INPROGRESS->value, data_get($execution->procedure_snapshot, 'audit_item.status'));
        $this->assertSame(Effectiveness::UNKNOWN->value, data_get($execution->procedure_snapshot, 'audit_item.effectiveness'));
        $this->assertSame(Applicability::APPLICABLE->value, data_get($execution->procedure_snapshot, 'audit_item.applicability'));
        $this->assertSame($execution->fingerprint, hash('sha256', json_encode([
            'outcome' => $execution->outcome->value, 'result' => $execution->result, 'exceptions' => $execution->exceptions,
            'sample_tested' => $execution->sample_tested, 'evidence_reference' => $execution->evidence_reference,
            'evidence_manifest' => $execution->evidence_manifest,
            'procedure_snapshot' => $execution->procedure_snapshot, 'executed_by' => $execution->executed_by,
            'executed_at' => $execution->executed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $this->expectException(LogicException::class);
        $execution->update(['result' => 'Rewritten result']);
    }

    public function test_assignee_executes_procedure_with_retained_governed_evidence(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/access-review.txt', 'attributable access-review evidence');

        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + [
            'evidence_attachment_ids' => [$attachment->id],
        ]);

        $evidence = $execution->evidence->sole();
        $this->assertSame($attachment->id, $evidence->file_attachment_id);
        $this->assertSame(hash('sha256', 'attributable access-review evidence'), $evidence->sha256);
        $this->assertSame($evidence->sha256, data_get($execution->evidence_manifest, '0.sha256'));
        $this->assertSame($execution->fingerprint, hash('sha256', json_encode($execution->fingerprintPayload(), JSON_THROW_ON_ERROR)));
        Storage::disk('private')->put($attachment->file_path, 'replaced source bytes');

        $assignee->givePermissionTo('Read Audits');
        $this->actingAs($assignee, 'web')->get(route('audit-procedure-execution-evidence.download', $evidence))
            ->assertSuccessful()->assertStreamedContent('attributable access-review evidence');
        $assignee->revokePermissionTo('Read Audits');
        $this->actingAs($assignee, 'web')->get(route('audit-procedure-execution-evidence.download', $evidence))->assertForbidden();
        try {
            $attachment->delete();
            $this->fail('A governed procedure source attachment was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('file_attachments', ['id' => $attachment->id]);
        }
    }

    public function test_mixed_unauthorized_evidence_rolls_back_execution_and_retained_copies(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $authorized = $this->acceptedEvidence($assignee, 'audit-procedures/authorized.txt', 'authorized evidence');
        $foreign = $this->acceptedEvidence(User::factory()->create(), 'audit-procedures/foreign.txt', 'foreign evidence');
        $this->actingAs($assignee, 'web');
        Livewire::test(ProceduresRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->mountTableAction('execute', $procedure)
            ->fillForm(['evidence_attachment_ids' => [$authorized->id, $foreign->id]])
            ->assertDontSee($foreign->file_name);
        $this->assertSame([$authorized->id => $authorized->file_name], FileAttachment::query()->eligibleGovernedEvidenceFor($assignee)
            ->whereKey([$authorized->id, $foreign->id])->pluck('file_name', 'id')->all());

        try {
            app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + [
                'evidence_attachment_ids' => [$authorized->id, $foreign->id],
            ]);
            $this->fail('A mixed unauthorized evidence selection created a workpaper execution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_attachment_ids.1', $exception->errors());
        }
        $this->assertDatabaseCount('audit_procedure_executions', 0);
        $this->assertDatabaseCount('audit_procedure_execution_evidence', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('governed-evidence/audit-procedure-execution'));
        $this->assertSame('planned', $procedure->fresh()->status);
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

    public function test_independent_review_requires_rework_before_a_later_version_and_preserves_history(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/closeout.txt', 'closeout workpaper evidence');
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + ['evidence_attachment_ids' => [$attachment->id]]);
        $assignee->givePermissionTo('Update Audits');
        try {
            app(AuditProcedureManager::class)->review($execution, $assignee, ['decision' => 'approved', 'review_summary' => 'Self reviewed.']);
            $this->fail('The executor reviewed their own workpaper.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewer', $exception->errors());
        }
        $review = app(AuditProcedureManager::class)->review($execution, $manager, [
            'decision' => 'rework_required', 'review_summary' => 'Expand the sample and document the two exceptions.',
        ]);
        $revised = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));

        $this->assertSame(2, $revised->version);
        $this->assertSame($execution->fingerprint, data_get($review->execution_snapshot, 'fingerprint'));
        $this->assertSame($review->fingerprint, hash('sha256', json_encode([
            'decision' => $review->decision->value, 'review_summary' => $review->review_summary,
            'execution_snapshot' => $review->execution_snapshot, 'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $this->assertDatabaseHas('audit_workpaper_reviews', ['id' => $review->id, 'decision' => 'rework_required']);
        $revisedExecution = app(AuditProcedureManager::class)->execute($revised, $assignee, $this->execution());
        $revisedReview = app(AuditProcedureManager::class)->review($revisedExecution, $manager, [
            'decision' => 'rework_required', 'review_summary' => 'The expanded workpaper still lacks reviewer evidence.',
        ]);
        $item->update(['status' => WorkflowStatus::COMPLETED, 'auditor_notes' => 'Concluded after supervisory review.', 'effectiveness' => Effectiveness::PARTIAL]);
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $this->closeoutPayload());
            $this->fail('Closeout accepted a latest workpaper version that still required rework.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_procedures', $exception->errors());
        }
        $final = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $finalExecution = app(AuditProcedureManager::class)->execute($final, $assignee, $this->execution());
        $finalReview = app(AuditProcedureManager::class)->review($finalExecution, $manager, [
            'decision' => 'approved', 'review_summary' => 'The final workpaper addresses the supervisory comments.',
        ]);
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $this->closeoutPayload());
        $this->assertSame(3, $final->version);
        $this->assertSame($review->fingerprint, data_get($submission->audit_procedure_snapshots, '0.supervisory_review.fingerprint'));
        $this->assertSame($revisedReview->fingerprint, data_get($submission->audit_procedure_snapshots, '1.supervisory_review.fingerprint'));
        $this->assertSame($finalReview->fingerprint, data_get($submission->audit_procedure_snapshots, '2.supervisory_review.fingerprint'));
        $this->expectException(LogicException::class);
        $review->update(['review_summary' => 'Rewritten']);
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
        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/rest.txt', 'REST governed evidence');
        $this->postJson("/api/audit-procedures/{$procedureId}/execute", $this->execution() + ['fingerprint' => str_repeat('a', 64), 'evidence_manifest' => [['sha256' => str_repeat('b', 64)]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['fingerprint', 'evidence_manifest']);
        $this->postJson("/api/audit-procedures/{$procedureId}/execute", $this->execution() + ['evidence_attachment_ids' => [$attachment->id]])
            ->assertCreated()->assertJsonPath('data.executed_by', $assignee->id)
            ->assertJsonPath('data.evidence.0.file_attachment_id', $attachment->id);
        Sanctum::actingAs($manager);
        $executionId = AuditProcedureExecution::query()->where('audit_procedure_id', $procedureId)->value('id');
        $this->postJson("/api/audit-procedure-executions/{$executionId}/review", ['decision' => 'approved', 'review_summary' => 'The workpaper supports its conclusion.', 'fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->postJson("/api/audit-procedure-executions/{$executionId}/review", ['decision' => 'approved', 'review_summary' => 'The workpaper supports its conclusion.'])
            ->assertCreated()->assertJsonPath('data.reviewed_by', $manager->id);
        $this->getJson("/api/audits/{$audit->id}/procedures")->assertOk()->assertJsonPath('data.0.id', $procedureId)
            ->assertJsonPath('data.0.execution.evidence_manifest', [])->assertJsonCount(0, 'data.0.execution.evidence');
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

        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/closeout-modal.txt', 'closeout workpaper evidence');
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + ['evidence_attachment_ids' => [$attachment->id]]);
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $closeout);
            $this->fail('An unreviewed workpaper was accepted at closeout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_procedures', $exception->errors());
        }
        $review = app(AuditProcedureManager::class)->review($execution, $manager, ['decision' => 'approved', 'review_summary' => 'The workpaper supports the documented conclusion.']);
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $closeout);
        $this->assertSame($procedure->id, data_get($submission->audit_procedure_snapshots, '0.id'));
        $this->assertSame($execution->fingerprint, data_get($submission->audit_procedure_snapshots, '0.execution.fingerprint'));
        $this->assertSame($review->fingerprint, data_get($submission->audit_procedure_snapshots, '0.supervisory_review.fingerprint'));
        $this->assertSame($attachment->file_name, data_get($submission->audit_procedure_snapshots, '0.execution.evidence_manifest.0.file_name_snapshot'));
        $restrictedViewer = User::factory()->create();
        $restrictedViewer->givePermissionTo('Read Audits');
        $this->actingAs($restrictedViewer, 'web');
        Livewire::test(CloseoutSubmissionsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->mountTableAction('inspect', $submission)->assertDontSee($attachment->file_name)->assertDontSee($execution->evidence->first()->sha256);
    }

    public function test_operator_history_export_factories_and_migration_preserve_complete_evidence(): void
    {
        [$audit, $manager, $assignee, $item] = $this->auditContext();
        $manager->givePermissionTo('Read Audits');
        $procedure = app(AuditProcedureManager::class)->define($audit, $manager, $this->definition($item, $assignee));
        $attachment = $this->acceptedEvidence($assignee, 'audit-procedures/operator.txt', 'operator workpaper evidence');
        $execution = app(AuditProcedureManager::class)->execute($procedure, $assignee, $this->execution() + ['evidence_attachment_ids' => [$attachment->id]]);

        $this->actingAs($manager, 'web');
        Livewire::test(ProceduresRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertTableActionVisible('review_workpaper', $procedure);
        $review = app(AuditProcedureManager::class)->review($execution, $manager, ['decision' => 'approved', 'review_summary' => 'The workpaper supports its conclusion.']);
        Livewire::test(ProceduresRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$procedure])->assertTableActionVisible('inspect', $procedure);
        $procedure->load(['assignee', 'execution.executor', 'execution.review.reviewer', 'execution.evidence.attachment.audit.members', 'execution.evidence.attachment.dataRequestResponse.dataRequest.audit.members']);
        $this->view('filament.audit-procedure', ['procedure' => $procedure])
            ->assertSee($procedure->objective)->assertSee($execution->result)->assertSee($execution->fingerprint)->assertSee($review->fingerprint)
            ->assertDontSee($attachment->file_name);
        $metadataRestrictedViewer = User::factory()->create();
        $metadataRestrictedViewer->givePermissionTo('Read Audits');
        $this->actingAs($metadataRestrictedViewer, 'web')->view('filament.audit-procedure', ['procedure' => $procedure])
            ->assertDontSee($attachment->file_name)->assertDontSee($execution->evidence->first()->sha256);
        $assignee->givePermissionTo('Read Audits');
        $this->actingAs($assignee, 'web')->view('filament.audit-procedure', ['procedure' => $procedure])
            ->assertSee($attachment->file_name)->assertSee($execution->evidence->first()->sha256);
        $columns = collect(AuditProcedureExporter::getColumns())->map->getName();
        $this->assertContains('execution.procedure_snapshot', $columns);
        $this->assertContains('execution.fingerprint', $columns);
        $this->assertContains('execution.review.execution_snapshot', $columns);

        $factoryExecution = AuditProcedureExecution::factory()->create();
        $this->assertSame('completed', $factoryExecution->procedure->status);
        $this->assertSame(WorkflowStatus::INPROGRESS, $factoryExecution->procedure->audit->status);
        $this->assertSame(WorkflowStatus::INPROGRESS, $factoryExecution->procedure->auditItem->status);
        $this->assertSame($factoryExecution->procedure->audit->manager_id, $factoryExecution->procedure->created_by);
        $this->assertSame($factoryExecution->procedure->assigned_to, $factoryExecution->executed_by);
        $this->assertSame($factoryExecution->fingerprint, hash('sha256', json_encode([
            'outcome' => $factoryExecution->outcome->value, 'result' => $factoryExecution->result,
            'exceptions' => $factoryExecution->exceptions, 'sample_tested' => $factoryExecution->sample_tested,
            'evidence_reference' => $factoryExecution->evidence_reference, 'evidence_manifest' => $factoryExecution->evidence_manifest,
            'procedure_snapshot' => $factoryExecution->procedure_snapshot,
            'executed_by' => $factoryExecution->executed_by, 'executed_at' => $factoryExecution->executed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $factoryReview = AuditWorkpaperReview::factory()->create();
        $this->assertNotSame($factoryReview->execution->executed_by, $factoryReview->reviewed_by);
        $this->assertSame($factoryReview->execution->fingerprint, data_get($factoryReview->execution_snapshot, 'fingerprint'));
        $this->assertSame($factoryReview->fingerprint, hash('sha256', json_encode([
            'decision' => $factoryReview->decision->value, 'review_summary' => $factoryReview->review_summary,
            'execution_snapshot' => $factoryReview->execution_snapshot, 'reviewed_by' => $factoryReview->reviewed_by,
            'reviewed_at' => $factoryReview->reviewed_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));
        $migration = require database_path('migrations/2026_08_24_390000_create_audit_procedure_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_procedure_executions', ['id' => $factoryExecution->id]);
        $reviewMigration = require database_path('migrations/2026_08_24_410000_create_audit_workpaper_reviews.php');
        $reviewMigration->up();
        $reviewMigration->down();
        $this->assertDatabaseHas('audit_workpaper_reviews', ['id' => $factoryReview->id]);
        $evidenceMigration = require database_path('migrations/2026_08_24_450000_create_audit_procedure_execution_evidence.php');
        $evidenceMigration->up();
        $evidenceMigration->down();
        $this->assertDatabaseHas('audit_procedure_execution_evidence', ['id' => $execution->evidence->first()?->id]);
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

    private function acceptedEvidence(User $actor, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = AuditEngagementBaseline::factory()->create(['launched_by' => $actor->id])->audit;
        $audit->update(['manager_id' => $actor->id]);
        $request = DataRequest::factory()->create(['audit_id' => $audit->id, 'created_by_id' => $actor->id, 'assigned_to_id' => $actor->id]);
        $response = DataRequestResponse::factory()->accepted()->create(['data_request_id' => $request->id, 'requester_id' => $actor->id, 'requestee_id' => $actor->id]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => basename($path), 'file_path' => $path, 'file_size' => strlen($contents),
            'description' => 'Governed audit-procedure evidence', 'uploaded_by' => $actor->id,
        ]);
    }

    private function execution(): array
    {
        return [
            'outcome' => 'needs_improvement', 'result' => 'Eight samples met criteria and two lacked reviewer timestamps.',
            'exceptions' => 'Two samples lacked attributable completion timestamps.', 'sample_tested' => 10,
            'evidence_reference' => 'Accepted data request DR-001.',
        ];
    }

    private function closeoutPayload(): array
    {
        return [
            'opinion' => 'needs_improvement', 'executive_summary' => 'The reviewed work program supports the conclusion.',
            'scope_limitations' => null, 'significant_matters' => 'Supervisory rework was completed.',
            'recommendations_summary' => 'Continue independent workpaper review.',
        ];
    }
}
