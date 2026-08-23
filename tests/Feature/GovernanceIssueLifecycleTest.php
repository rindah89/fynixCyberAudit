<?php

namespace Tests\Feature;

use App\Access\FileAccess;
use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Filament\Exports\GovernanceIssueLifecycleExporter;
use App\Filament\Resources\GovernanceIssueLifecycleResource;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\GovernanceIssueClosureEvidence;
use App\Models\GovernanceIssueLifecycle;
use App\Models\GovernanceIssueTransition;
use App\Models\Risk;
use App\Models\User;
use App\Remediation\Remediation;
use App\Services\GovernedEvidenceHasher;
use App\Services\RiskPortfolioManager;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GovernanceIssueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
        Storage::fake('private');
    }

    public function test_issue_moves_through_attributable_remediation_and_independent_closure(): void
    {
        [$manager, $risk, $issue] = $this->riskIssue();
        $assignee = User::factory()->create();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Enterprise risk treatment']);
        $manager->revokePermissionTo('Manage Remediation');
        Sanctum::actingAs($manager);

        $this->assertSame('open', $issue->lifecycle->status->value);
        $this->assertSame($manager->id, $issue->lifecycle->transitions->first()->transitioned_by);
        $response = $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'due_date' => now()->addMonth()->toDateString(),
            'rationale' => 'Treat the exposure through the governed remediation plan.',
        ])->assertCreated()->assertJsonPath('data.status', 'in_remediation')
            ->assertJsonPath('data.issue.status', 'in_remediation');
        $taskId = $response->json('data.remediation_task.id');
        $this->assertDatabaseHas('remediation_tasks', ['id' => $taskId, 'assignee_id' => $assignee->id]);
        $this->assertSame('action_required', $risk->fresh()->portfolio_governance_status);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", [
            'rationale' => 'Treatment implementation is ready for independent review.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['remediation_task']);

        $task = $issue->fresh()->remediationTask;
        $manager->givePermissionTo('Manage Remediation');
        app(Remediation::class)->updateTaskStatus($manager, $task, 'Completed');
        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", [
            'rationale' => 'Treatment implementation is ready for independent review.',
        ])->assertOk()->assertJsonPath('data.status', 'verification');
        $this->assertSame('action_required', $risk->fresh()->portfolio_governance_status);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The mitigating control operates as intended.',
            'evidence_reference' => 'OPERATOR-REF-2026-081',
        ])->assertForbidden();

        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        $evidence = $this->acceptedEvidence($verifier, 'closures/risk-treatment.txt', 'verified treatment bytes');
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The mitigating control operates as intended and addresses the reviewed exposure.',
            'evidence_reference' => 'OPERATOR-REF-2026-081',
            'evidence_attachment_ids' => [$evidence->id],
        ])->assertOk()->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.verified_by', $verifier->id)
            ->assertJsonPath('data.issue.status', 'closed')
            ->assertJsonPath('data.closure_evidence.0.file_attachment_id', $evidence->id)
            ->assertJsonPath('data.closure_evidence.0.sha256', hash('sha256', 'verified treatment bytes'));
        $this->assertSame('mitigate', $risk->fresh()->portfolio_governance_status);
        $this->assertDatabaseHas('governance_issue_transitions', [
            'to_status' => 'closed', 'transitioned_by' => $verifier->id,
            'evidence_reference' => 'OPERATOR-REF-2026-081',
        ]);
        $this->assertDatabaseHas('governance_issue_closure_evidence', [
            'file_attachment_id' => $evidence->id,
            'data_request_response_id_snapshot' => $evidence->data_request_response_id,
            'response_status_snapshot' => 'Accepted',
            'file_name_snapshot' => $evidence->file_name,
            'sha256' => hash('sha256', 'verified treatment bytes'),
            'linked_by' => $verifier->id,
        ]);
        Storage::disk('private')->put($evidence->file_path, 'later replacement bytes');
        $closureEvidence = $issue->fresh()->lifecycle->closureEvidence->first();
        $this->assertSame(hash('sha256', 'verified treatment bytes'), $closureEvidence->sha256);
        $this->assertSame('verified treatment bytes', Storage::disk('private')->get($closureEvidence->file_path_snapshot));
        $exporter = new GovernanceIssueLifecycleExporter(new Export, [
            'closure_evidence_count' => 'Governed Evidence Files',
            'closure_evidence_sha256' => 'Closure Evidence SHA-256',
        ], []);
        $this->assertSame(['1', hash('sha256', 'verified treatment bytes')], $exporter($issue->fresh()->lifecycle->load('closureEvidence')));
        $otherUser = User::factory()->create();
        $otherAudit = Audit::factory()->create(['manager_id' => $otherUser->id]);
        $otherRequest = DataRequest::factory()->create([
            'audit_id' => $otherAudit->id, 'created_by_id' => $otherUser->id, 'assigned_to_id' => $otherUser->id,
        ]);
        $otherResponse = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $otherRequest->id, 'requester_id' => $otherUser->id, 'requestee_id' => $otherUser->id,
        ]);
        FileAttachment::query()->create([
            'data_request_response_id' => $otherResponse->id, 'audit_id' => $otherAudit->id,
            'file_name' => $evidence->file_name, 'file_path' => $evidence->file_path,
            'file_size' => strlen('later replacement bytes'), 'description' => 'Colliding path', 'uploaded_by' => $otherUser->id,
        ]);
        FileAttachment::query()->whereKey($evidence)->update(['file_path' => 'closures/mutated-record-path.txt']);
        Storage::fake('local');
        Storage::disk('local')->put($evidence->file_path, 'wrong disk bytes');
        setting(['storage.driver' => 'local']);
        $download = $this->actingAs($verifier, 'web')->get(route('governance-closure-evidence.download', $closureEvidence));
        $download->assertSuccessful();
        $this->assertSame('verified treatment bytes', $download->streamedContent());
        setting(['storage.driver' => 'private']);
        $this->actingAs($verifier, 'web')->get(GovernanceIssueLifecycleResource::getUrl('view', ['record' => $issue->fresh()->lifecycle]))->assertOk();
        $this->actingAs($manager, 'web')->get(route('governance-closure-evidence.download', $closureEvidence))->assertForbidden();
    }

    public function test_closed_issue_can_be_reopened_while_history_remains_append_only(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Risk action plan']);
        Sanctum::actingAs($manager);
        $taskId = $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id, 'priority' => 'Medium',
            'due_date' => now()->addMonth()->toDateString(), 'rationale' => 'Create a tracked corrective action.',
        ])->assertCreated()->json('data.remediation_task.id');
        app(Remediation::class)->updateTaskStatus($manager, $issue->fresh()->remediationTask, 'Completed');
        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", ['rationale' => 'Ready for verification.'])->assertOk();
        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        $evidence = $this->acceptedEvidence($verifier, 'closures/first-closure.txt', 'first closure bytes');
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'Verified independently.',
            'evidence_attachment_ids' => [$evidence->id],
        ])->assertOk();

        Sanctum::actingAs($manager);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/reopen", ['rationale' => 'A regression invalidated the closure.'])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.issue.status', 'open');
        $this->assertDatabaseCount('governance_issue_transitions', 5);
        $this->assertDatabaseHas('governance_issue_closure_evidence', ['file_attachment_id' => $evidence->id]);
        $this->assertDatabaseHas('governance_issue_transitions', ['to_status' => 'closed', 'remediation_task_id_snapshot' => $taskId]);

        $transition = GovernanceIssueTransition::query()->where('to_status', 'closed')->firstOrFail();
        $this->expectException(\LogicException::class);
        $transition->update(['rationale' => 'Rewritten history']);
    }

    public function test_closure_requires_accepted_existing_evidence_authorized_to_the_verifier(): void
    {
        [$manager, , $issue] = $this->riskIssueReadyForVerification();
        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        Sanctum::actingAs($verifier);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'No governed evidence supplied.',
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids');

        $inaccessible = $this->acceptedEvidence(User::factory()->create(), 'closures/inaccessible.txt', 'other audit bytes');
        $this->assertFalse(FileAttachment::query()->eligibleGovernedEvidenceFor($verifier)->whereKey($inaccessible)->exists());
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'Evidence belongs to an unrelated audit.',
            'evidence_attachment_ids' => [$inaccessible->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.0');

        $notAccepted = $this->acceptedEvidence($verifier, 'closures/not-accepted.txt', 'unaccepted bytes', false);
        $this->assertFalse(FileAttachment::query()->eligibleGovernedEvidenceFor($verifier)->whereKey($notAccepted)->exists());
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'Response has not been accepted.',
            'evidence_attachment_ids' => [$notAccepted->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.0');

        $missing = $this->acceptedEvidence($verifier, 'closures/missing.txt', 'temporary bytes');
        $this->assertTrue(FileAttachment::query()->eligibleGovernedEvidenceFor($verifier)->whereKey($missing)->exists());
        Storage::disk('private')->delete($missing->file_path);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The evidence content is missing.',
            'evidence_attachment_ids' => [$missing->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.0');

        $oversized = $this->acceptedEvidence($verifier, 'closures/oversized.bin', str_repeat('x', (10 * 1024 * 1024) + 1));
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The evidence exceeds the governed request bound.',
            'evidence_attachment_ids' => [$oversized->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.0');

        $this->assertSame('verification', $issue->fresh()->status->value);
    }

    public function test_closure_evidence_snapshot_is_append_only_and_preserves_file_identity(): void
    {
        [, , $issue] = $this->riskIssueReadyForVerification();
        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        $attachment = $this->acceptedEvidence($verifier, 'closures/immutable.txt', 'immutable bytes');
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'Evidence was inspected and accepted.',
            'evidence_attachment_ids' => [$attachment->id],
        ])->assertOk();

        $evidence = GovernanceIssueClosureEvidence::query()->firstOrFail();
        $migration = require database_path('migrations/2026_08_23_230000_create_governance_issue_closure_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('governance_issue_closure_evidence', ['id' => $evidence->id, 'sha256' => $evidence->sha256]);
        try {
            $evidence->update(['sha256' => str_repeat('0', 64)]);
            $this->fail('Closure evidence snapshots must not be mutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            app(FileAccess::class)->deleteUnreferencedFileAttachmentPath('private', $attachment->file_path);
            $this->fail('Governed evidence content should not be removable through the file interface.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file_path', $exception->errors());
            $this->assertTrue(Storage::disk('private')->exists($attachment->file_path));
        }

        try {
            $attachment->update(['file_path' => 'closures/repointed.txt']);
            $this->fail('A governed attachment identity should not be mutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('cannot change identity', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $attachment->delete();
    }

    public function test_hashing_stops_at_the_runtime_bound_even_when_stream_metadata_cannot_be_trusted(): void
    {
        $stream = fopen('php://temp/maxmemory:'.(GovernedEvidenceHasher::MAX_FILE_BYTES + 16384), 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, str_repeat('x', GovernedEvidenceHasher::MAX_FILE_BYTES + 1));
        rewind($stream);

        try {
            Storage::fake('bounded-snapshot');
            app(GovernedEvidenceHasher::class)->snapshotStream(
                $stream,
                Storage::disk('bounded-snapshot'),
                'closure/snapshot.bin',
                0,
                'evidence_attachment_ids.0',
            );
            $this->fail('An oversized stream should fail before it is consumed without bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_attachment_ids.0', $exception->errors());
            $this->assertLessThanOrEqual(GovernedEvidenceHasher::MAX_FILE_BYTES + 8192, ftell($stream));
            Storage::disk('bounded-snapshot')->assertMissing('closure/snapshot.bin');
        } finally {
            fclose($stream);
        }
    }

    public function test_failed_multi_file_closure_removes_all_uncommitted_retained_copies(): void
    {
        [, , $issue] = $this->riskIssueReadyForVerification();
        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        $valid = $this->acceptedEvidence($verifier, 'closures/valid-first.txt', 'bounded retained bytes');
        $missing = $this->acceptedEvidence($verifier, 'closures/missing-second.txt', 'temporary bytes');
        Storage::disk('private')->delete($missing->file_path);
        Sanctum::actingAs($verifier);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'Both files must be retained atomically.',
            'evidence_attachment_ids' => [$valid->id, $missing->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');

        $this->assertSame([], Storage::disk('private')->allFiles('governance-closure-evidence'));
        $this->assertDatabaseCount('governance_issue_closure_evidence', 0);
        $this->assertSame('verification', $issue->fresh()->status->value);
    }

    public function test_owner_can_read_history_but_outsider_cannot_mutate_or_view(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        Sanctum::actingAs($manager);
        $this->getJson("/api/governance-issues/risk/{$issue->id}")
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonCount(1, 'data.transitions');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/governance-issues/risk/{$issue->id}")->assertForbidden();
        $this->postJson("/api/governance-issues/risk/{$issue->id}/reopen", ['rationale' => 'Unauthorized.'])->assertForbidden();
    }

    public function test_source_issue_status_and_remediation_link_cannot_bypass_the_lifecycle(): void
    {
        [, , $issue] = $this->riskIssue();

        try {
            $issue->update(['status' => 'closed']);
            $this->fail('Direct status mutation should be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('governed lifecycle', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $issue->delete();
    }

    public function test_scoped_workspace_and_export_expose_current_state_without_lazy_loading(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $this->actingAs($manager)->get(GovernanceIssueLifecycleResource::getUrl('index'))->assertOk();
        $record = GovernanceIssueLifecycleResource::getEloquentQuery()->findOrFail($issue->lifecycle->id);
        $this->assertTrue($record->relationLoaded('issue'));
        $this->assertTrue($record->issue->relationLoaded('owner'));
        $this->assertTrue($record->relationLoaded('remediationTask'));
        $this->assertTrue($record->relationLoaded('closureEvidence'));

        $columns = collect(GovernanceIssueLifecycleExporter::getColumns())->map->getName();
        $this->assertContains('source_type', $columns);
        $this->assertContains('remediationTask.number', $columns);
        $this->assertContains('verification_summary', $columns);
        $this->assertContains('closure_evidence_count', $columns);
        $this->assertContains('closure_evidence_sha256', $columns);
        $exported = GovernanceIssueLifecycleExporter::modifyQuery(GovernanceIssueLifecycle::query()->whereKey($record))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('issue'));
        $this->assertTrue($exported->relationLoaded('closureEvidence'));
    }

    public function test_migration_retry_and_routine_rollback_preserve_governed_history(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Migration-safe treatment']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id, 'priority' => 'High',
            'due_date' => now()->addMonth()->toDateString(), 'rationale' => 'Preserve this governed handoff.',
        ])->assertCreated();
        $transitionCount = GovernanceIssueTransition::query()->count();

        $migration = require database_path('migrations/2026_08_23_220000_create_governance_issue_lifecycles.php');
        $migration->up();
        $this->assertSame('in_remediation', $issue->fresh()->lifecycle->status->value);
        $this->assertSame($transitionCount, GovernanceIssueTransition::query()->count());

        $migration->down();
        $this->assertTrue(Schema::hasTable('governance_issue_lifecycles'));
        $this->assertTrue(Schema::hasTable('governance_issue_transitions'));
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_id' => $issue->id, 'status' => 'in_remediation']);
    }

    private function riskIssue(): array
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage Risk Portfolio', 'Manage Remediation', 'Manage Issue Lifecycle']);
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 4, 'residual_impact' => 4]);
        $risk->governanceProfile()->create([
            'owner_id' => $manager->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly',
            'strategic_objective' => 'Protect enterprise value.', 'next_review_at' => now()->addQuarter(),
        ]);
        $review = app(RiskPortfolioManager::class)->review($risk, $manager, RiskGovernanceDecision::Mitigate, [
            'summary' => 'Risk treatment is required.', 'next_review_at' => now()->addQuarter(),
        ]);

        return [$manager, $risk, $review->issue];
    }

    private function riskIssueReadyForVerification(): array
    {
        [$manager, $risk, $issue] = $this->riskIssue();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Evidence-backed treatment']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id,
            'priority' => 'High',
            'due_date' => now()->addMonth()->toDateString(),
            'rationale' => 'Implement and evidence the corrective action.',
        ])->assertCreated();
        app(Remediation::class)->updateTaskStatus($manager, $issue->fresh()->remediationTask, 'Completed');
        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", [
            'rationale' => 'Corrective action and evidence are ready for review.',
        ])->assertOk();

        return [$manager, $risk, $issue->fresh()];
    }

    private function acceptedEvidence(User $auditManager, string $path, string $contents, bool $accepted = true): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $auditManager->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id,
            'created_by_id' => $auditManager->id,
            'assigned_to_id' => $auditManager->id,
        ]);
        $response = DataRequestResponse::factory()->create([
            'data_request_id' => $request->id,
            'requester_id' => $auditManager->id,
            'requestee_id' => $auditManager->id,
            'status' => $accepted ? 'Accepted' : 'Responded',
        ]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id,
            'audit_id' => $audit->id,
            'file_name' => basename($path),
            'file_path' => $path,
            'file_size' => strlen($contents),
            'description' => 'Governed closure evidence',
            'uploaded_by' => $auditManager->id,
        ]);
    }
}
