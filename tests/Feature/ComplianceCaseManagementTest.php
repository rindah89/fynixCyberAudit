<?php

namespace Tests\Feature;

use App\Access\FileAccess;
use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\ComplianceCases\ComplianceCaseInterviewManager;
use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseInterviewStatus;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ActionIssuesRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EvidenceSubmissionsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InterviewsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\LegalHoldsRelationManager;
use App\Models\Audit;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseActionIssue;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInterviewEvent;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldAcknowledgement;
use App\Models\ComplianceCaseLegalHoldRelease;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
use App\Remediation\Remediation;
use App\Services\GovernanceIssueLifecycleManager;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
        Config::set('enterprise.modules.remediation', true);
    }

    public function test_manager_opens_investigates_resolves_and_independently_closes_governed_case(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $resolutionManager = User::factory()->create();
        $resolutionManager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $service = app(ComplianceCaseManager::class);

        $case = $service->open($manager, [
            'title' => 'Potential conflict in supplier selection', 'category' => ComplianceCaseCategory::ConflictOfInterest->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A selection-panel member may have an undeclared interest.',
            'source_channel' => 'Ethics hotline', 'reporter_reference' => 'HOTLINE-42', 'confidential' => true,
            'summary' => 'Open a governed investigation without asserting that the allegation is true.',
        ]);
        $this->assertMatchesRegularExpression('/^CC-\d{4}-\d{6}$/', $case->number);
        $this->assertSame(ComplianceCaseStatus::New, $case->status);
        $this->assertSame(1, $case->events()->count());

        try {
            $service->record($outsider, $case, ['assigned_to' => 999999999, 'summary' => 'Unauthorized malformed probe.']);
            $this->fail('Expected direct service authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $service->record($manager, $case, [
                'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $outsider->id,
                'triage_summary' => 'Invalid assignee.', 'summary' => 'Reject unauthorized investigator assignment.',
            ]);
            $this->fail('Expected assignment to require investigator permission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assigned_to', $exception->errors());
        }
        $service->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'due_at' => now()->addWeek(), 'triage_summary' => 'The allegation is within compliance scope and requires fact finding.',
            'summary' => 'Assign an investigator and preserve the triage basis.',
        ]);
        $this->approveInvestigationPlan($case->refresh(), $investigator, $manager);
        $service->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'Interviews and procurement records are being reviewed.',
            'summary' => 'Begin the assigned investigation.',
        ]);
        $this->completeInvestigationPlan($case->refresh(), $investigator);
        $service->record($resolutionManager, $case->refresh(), [
            'investigation_summary' => 'The independent case manager added a material investigation conclusion.',
            'summary' => 'Add a material investigation decision without changing status.',
        ]);
        $this->approveInvestigationReport($case->refresh(), $investigator);
        try {
            $service->record($investigator, $case->refresh(), [
                'assigned_to' => $outsider->id, 'summary' => 'Investigators cannot reassign cases.',
            ]);
            $this->fail('Expected investigator field scope to be enforced.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $service->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'investigation_summary' => 'Records and interviews identified an undisclosed relationship.',
            'resolution_summary' => 'The panel member was removed and the selection was independently reperformed.',
            'summary' => 'Record the attributable investigation conclusion and response.',
        ]);
        try {
            $service->record($resolutionManager, $case->refresh(), [
                'status' => ComplianceCaseStatus::Closed->value, 'closure_summary' => 'Resolver self closure.',
                'summary' => 'Resolution actors cannot close their own decision.',
            ]);
            $this->fail('Expected the resolution actor to be excluded from final closure.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $service->record($manager, $case->refresh(), [
                'status' => ComplianceCaseStatus::Closed->value, 'closure_summary' => 'Self closure.', 'summary' => 'Not independent.',
            ]);
            $this->fail('Expected the opener to be excluded from final closure.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $closed = $service->record($reviewer, $case->refresh(), [
            'status' => ComplianceCaseStatus::Closed->value,
            'closure_summary' => 'Reviewed the retained investigation and resolution record; closure requirements are satisfied.',
            'summary' => 'Independently close the governed case.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Closed, $case->fresh()->status);
        $this->assertSame(6, $case->events()->count());
        try {
            $service->record($reviewer, $case->refresh(), ['closure_summary' => 'Rewrite.', 'summary' => 'Terminal rewrite.']);
            $this->fail('Expected closed cases to remain terminal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $this->assertSame(6, $case->events()->count());
        $payload = [
            'compliance_case_id' => $closed->compliance_case_id, 'version' => $closed->version,
            'event_type' => $closed->event_type, 'before_snapshot' => $closed->before_snapshot,
            'after_snapshot' => $closed->after_snapshot, 'summary' => $closed->summary,
            'recorded_by' => $closed->recorded_by, 'recorded_at' => $closed->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $closed->fingerprint);
        $this->assertSame($investigator->email, data_get($closed->after_snapshot, 'assigned_to.email'));
    }

    public function test_action_required_case_is_bound_to_independently_closed_remediation_evidence(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $manager->givePermissionTo(['Manage Issue Lifecycle', 'Manage Remediation']);
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $verifier = User::factory()->create();
        $verifier->givePermissionTo(['Verify Issue Closure', 'Read Compliance Cases']);
        $lifecycleOutsider = User::factory()->create();
        $lifecycleOutsider->givePermissionTo('Manage Issue Lifecycle');
        $service = app(ComplianceCaseManager::class);

        $case = $service->open($manager, [
            'title' => 'Corrective action case', 'category' => ComplianceCaseCategory::PolicyViolation->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A deliberate allegation requires investigation.',
            'summary' => 'Open the governed case.',
        ]);
        $service->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'The matter requires investigation.', 'summary' => 'Triage and assign.',
        ]);
        $this->approveInvestigationPlan($case->refresh(), $investigator, $manager);
        $service->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'The investigator is evaluating the retained facts.', 'summary' => 'Begin investigation.',
        ]);
        $this->completeInvestigationPlan($case->refresh(), $investigator);
        $actionEvent = $service->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::ActionRequired->value,
            'investigation_summary' => 'The retained facts require a corrective control change.',
            'summary' => 'Record the attributable action-required decision.',
        ]);
        $this->approveInvestigationReport($case->refresh(), $investigator);

        $issue = ComplianceCaseActionIssue::query()->with('lifecycle.transitions')->sole();
        $this->assertSame($actionEvent->id, $issue->compliance_case_event_id);
        $this->assertSame($actionEvent->fingerprint, data_get($issue->source_snapshot, 'event.fingerprint'));
        $this->assertSame('open', $issue->lifecycle->status->value);
        $payload = $issue->only([
            'compliance_case_id', 'compliance_case_event_id', 'owner_id', 'opened_by', 'title', 'description', 'severity',
        ]) + ['source_snapshot' => $issue->source_snapshot, 'opened_at' => $issue->opened_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($payload)), $issue->fingerprint);
        try {
            app(GovernanceIssueLifecycleManager::class)->show($issue, $lifecycleOutsider);
            $this->fail('Expected generic lifecycle permission not to bypass compliance-case privacy.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $service->record($manager, $case->refresh(), [
                'status' => ComplianceCaseStatus::Resolved->value,
                'resolution_summary' => 'Attempted resolution before independent verification.',
                'summary' => 'This must remain blocked.',
            ]);
            $this->fail('Expected unresolved action remediation to block case resolution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $project = app(Remediation::class)->createProject($manager, ['name' => 'Compliance corrective action']);
        $lifecycle = app(GovernanceIssueLifecycleManager::class);
        $lifecycle->handoff($issue, $manager, [
            'remediation_project_id' => $project->id, 'priority' => 'High',
            'due_date' => now()->addWeek()->toDateString(), 'rationale' => 'Implement the required corrective control.',
        ]);
        $task = $issue->fresh()->remediationTask;
        app(Remediation::class)->updateTaskStatus($manager, $task, 'Completed');
        $lifecycle->requestVerification($issue, $manager, 'Corrective work is ready for independent verification.');

        $audit = Audit::factory()->create(['manager_id' => $verifier->id]);
        $request = DataRequest::factory()->create(['audit_id' => $audit->id, 'created_by_id' => $verifier->id, 'assigned_to_id' => $verifier->id]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $verifier->id, 'requestee_id' => $verifier->id,
        ]);
        $bytes = 'independently verified compliance corrective action';
        $path = 'closures/compliance-case-action.txt';
        Storage::disk('private')->put($path, $bytes);
        $attachment = FileAttachment::factory()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => 'compliance-case-action.txt', 'file_path' => $path,
            'file_size' => strlen($bytes), 'uploaded_by' => $verifier->id,
        ]);
        $lifecycle->close($issue, $verifier, [
            'verification_summary' => 'The corrective control is independently verified.',
            'evidence_attachment_ids' => [$attachment->id],
        ]);
        $resolved = $service->record($manager, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'The independently verified corrective action resolves the case.',
            'summary' => 'Resolve only after governed issue closure.',
        ]);

        $this->assertSame(ComplianceCaseStatus::Resolved, $case->fresh()->status);
        $this->assertSame('closed', $issue->fresh()->lifecycle->status->value);
        $this->assertSame(hash('sha256', CanonicalJson::encode($payload)), $issue->fresh()->fingerprint);
        $this->assertSame('resolved', $resolved->event_type);
        try {
            $lifecycle->reopen($issue, $manager, 'Attempt to reopen after case resolution.');
            $this->fail('Expected post-resolution issue reopening to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->actingAs($investigator)->getJson("/api/compliance-cases/{$case->id}/action-issues")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.source_snapshot.event.fingerprint', $actionEvent->fingerprint)
            ->assertJsonPath('data.0.lifecycle.status', 'closed');
        Livewire::actingAs($investigator);
        Livewire::test(ActionIssuesRelationManager::class, ['ownerRecord' => $case->refresh(), 'pageClass' => ViewComplianceCase::class])
            ->assertCanSeeTableRecords([$issue])->assertTableActionVisible('inspect', $issue);

        $factoryIssue = ComplianceCaseActionIssue::factory()->create();
        $this->assertSame('open', $factoryIssue->lifecycle->status->value);
        $factoryPayload = $factoryIssue->only([
            'compliance_case_id', 'compliance_case_event_id', 'owner_id', 'opened_by', 'title', 'description', 'severity',
        ]) + ['source_snapshot' => $factoryIssue->source_snapshot, 'opened_at' => $factoryIssue->opened_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryPayload)), $factoryIssue->fingerprint);

        $migration = require database_path('migrations/2026_08_25_080000_create_compliance_case_action_issues.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('compliance_case_action_issues', ['id' => $issue->id, 'fingerprint' => $issue->fingerprint]);
    }

    public function test_rest_and_operator_interfaces_are_scoped_server_owned_and_paginated(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $otherInvestigator = User::factory()->create();
        $otherInvestigator->givePermissionTo('Investigate Compliance Cases');
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $outsider = User::factory()->create();

        $response = $this->actingAs($manager)->postJson('/api/compliance-cases', [
            'title' => 'Books and records concern', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::Critical->value, 'allegation' => 'A deliberate allegation for investigation.',
            'summary' => 'Open the case.', 'number' => 'CALLER-1', 'status' => ComplianceCaseStatus::Closed->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['number', 'status']);
        $this->assertNull($response->json('data'));
        $caseId = $this->actingAs($manager)->postJson('/api/compliance-cases', [
            'title' => 'Books and records concern', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::Critical->value, 'allegation' => 'A deliberate allegation for investigation.',
            'summary' => 'Open the case.',
        ])->assertCreated()->json('data.id');
        $case = ComplianceCase::query()->findOrFail($caseId);
        app(ComplianceCaseManager::class)->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Investigate.', 'summary' => 'Assign the case.',
        ]);
        $otherCase = ComplianceCase::factory()->create(['assigned_to' => $otherInvestigator->id, 'status' => ComplianceCaseStatus::Triaged]);

        $this->actingAs($investigator)->getJson('/api/compliance-cases')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $case->id);
        $this->actingAs($reader)->getJson('/api/compliance-cases?per_page=1')->assertOk()->assertJsonPath('per_page', 1)->assertJsonPath('total', 2);
        $this->actingAs($outsider)->getJson('/api/compliance-cases')->assertForbidden();
        $this->actingAs($investigator)->getJson('/api/compliance-cases/'.$otherCase->id)->assertForbidden();
        $this->actingAs($investigator)->getJson('/api/compliance-cases/'.$case->id.'/events?per_page=1')
            ->assertOk()->assertJsonPath('per_page', 1)->assertJsonPath('total', 2);

        Livewire::actingAs($investigator);
        Livewire::test(EventsRelationManager::class, ['ownerRecord' => $case->refresh(), 'pageClass' => ViewComplianceCase::class])
            ->assertCanSeeTableRecords($case->events)->assertTableActionVisible('inspect', $case->events()->first());
        $event = $case->events()->reorder()->with('actor:id,name')->latest('version')->firstOrFail();
        $rendered = view('filament.compliance-case-event', ['record' => $event])->render();
        $this->assertStringContainsString('Assign the case.', $rendered);
        $this->assertStringContainsString($event->fingerprint, $rendered);
    }

    public function test_event_bound_factories_immutability_and_migration_retention_are_coherent(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $case = ComplianceCase::factory()->create(['opened_by' => $manager->id]);
        $factoryEvent = ComplianceCaseEvent::factory()->create(['compliance_case_id' => $case->id, 'recorded_by' => $manager->id]);
        $payload = [
            'compliance_case_id' => $factoryEvent->compliance_case_id, 'version' => $factoryEvent->version,
            'event_type' => $factoryEvent->event_type, 'before_snapshot' => $factoryEvent->before_snapshot,
            'after_snapshot' => $factoryEvent->after_snapshot, 'summary' => $factoryEvent->summary,
            'recorded_by' => $factoryEvent->recorded_by, 'recorded_at' => $factoryEvent->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factoryEvent->fingerprint);
        $investigator = User::factory()->create();
        $assigned = ComplianceCase::factory()->create([
            'opened_by' => $manager->id, 'assigned_to' => $investigator->id, 'status' => ComplianceCaseStatus::Triaged,
        ]);
        $laterFactoryEvent = ComplianceCaseEvent::factory()->create([
            'compliance_case_id' => $assigned->id, 'recorded_by' => $manager->id, 'version' => 2,
        ]);
        $this->assertSame('updated', $laterFactoryEvent->event_type);
        $this->assertNotNull($laterFactoryEvent->before_snapshot);
        $this->assertSame($investigator->id, data_get($laterFactoryEvent->after_snapshot, 'assigned_to.id'));

        try {
            $factoryEvent->update(['summary' => 'Rewrite']);
            $this->fail('Expected case history to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        foreach (range(2, 200) as $version) {
            ComplianceCaseEvent::factory()->create(['compliance_case_id' => $case->id, 'recorded_by' => $manager->id, 'version' => $version]);
        }
        try {
            app(ComplianceCaseManager::class)->record($manager, $case, ['status' => ComplianceCaseStatus::Triaged->value, 'summary' => 'Event 201.']);
            $this->fail('Expected exact event bound rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $this->assertSame(200, $case->events()->count());
        $migration = require database_path('migrations/2026_08_24_650000_create_governed_compliance_cases.php');
        $migration->down();
        $this->assertDatabaseHas('compliance_case_events', ['id' => $factoryEvent->id, 'fingerprint' => $factoryEvent->fingerprint]);
    }

    public function test_module_boundary_hides_case_interfaces_when_disabled(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        Config::set('enterprise.modules.compliance_cases', false);

        $this->actingAs($manager)->getJson('/api/compliance-cases')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/compliance-cases', [
            'title' => 'Disabled', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Low->value, 'allegation' => 'Disabled.', 'summary' => 'Disabled.',
        ])->assertForbidden();
        $this->assertFalse(ComplianceCaseResource::shouldRegisterNavigation());
    }

    public function test_investigator_retains_bounded_case_evidence_with_exact_workspace_and_source_acl(): void
    {
        Storage::fake('private');
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Evidence-backed investigation', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A deliberate allegation.',
            'summary' => 'Open the governed case.',
        ]);
        app(ComplianceCaseManager::class)->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Evidence collection is required.', 'summary' => 'Assign the investigator.',
        ]);
        $attachment = $this->acceptedEvidence($investigator, 'compliance/source.txt', 'original compliance evidence');
        $foreign = $this->acceptedEvidence(User::factory()->create(), 'compliance/foreign.txt', 'foreign evidence');

        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/evidence", [
            'summary' => 'Retain the authorized investigation record.',
            'evidence_attachment_ids' => [$attachment->id, $foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');
        $this->assertDatabaseCount('compliance_case_evidence_submissions', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('governed-evidence/compliance-cases'));

        $submissionId = $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/evidence", [
            'summary' => 'Retain the authorized investigation record.', 'evidence_attachment_ids' => [$attachment->id],
            'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable()->json('data.id');
        $this->assertNull($submissionId);
        $storeResponse = $this->postJson("/api/compliance-cases/{$case->id}/evidence", [
            'summary' => 'Retain the authorized investigation record.', 'evidence_attachment_ids' => [$attachment->id],
        ])->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.evidence.0.attachment')
            ->assertJsonMissingPath('data.evidence.0.data_request_response');
        $submissionId = $storeResponse->json('data.id');
        $submission = ComplianceCaseEvidenceSubmission::query()->findOrFail($submissionId);
        $payload = $submission->only(['compliance_case_id', 'version', 'summary', 'case_snapshot', 'latest_event_snapshot', 'evidence_manifest', 'recorded_by', 'actor_snapshot']);
        $payload['recorded_at'] = $submission->recorded_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($payload)), $submission->fingerprint);
        $evidence = $submission->evidence()->firstOrFail();
        $this->assertSame(hash('sha256', 'original compliance evidence'), $evidence->sha256);
        $this->assertSame($case->events()->reorder()->latest('version')->value('fingerprint'), data_get($submission->latest_event_snapshot, 'fingerprint'));

        $outsider = User::factory()->create();
        try {
            app(ComplianceCaseEvidenceManager::class)->submit($outsider, $case, [
                'summary' => '', 'evidence_attachment_ids' => [PHP_INT_MAX],
            ]);
            $this->fail('Expected current case authorization before source validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('compliance_case_evidence_submissions', 1);

        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}/evidence")
            ->assertOk()->assertJsonCount(0, 'data.0.evidence')->assertJsonMissingPath('data.0.evidence_manifest');
        $this->actingAs($reader)->get(route('compliance-case-evidence.download', $evidence))->assertForbidden();
        $this->actingAs($investigator)->getJson("/api/compliance-cases/{$case->id}/evidence")
            ->assertOk()->assertJsonPath('data.0.evidence.0.sha256', $evidence->sha256)
            ->assertJsonMissingPath('data.0.evidence.0.attachment')
            ->assertJsonMissingPath('data.0.evidence.0.data_request_response');
        $this->get(route('compliance-case-evidence.download', $evidence))->assertOk();
        Storage::disk('private')->put($attachment->file_path, 'later changed source');
        $this->get(route('compliance-case-evidence.download', $evidence))->assertOk()->assertStreamedContent('original compliance evidence');
        $this->assertThrows(fn () => $attachment->delete(), \LogicException::class);
        $this->assertThrows(fn () => $submission->update(['summary' => 'Rewrite']), \LogicException::class);

        $closed = ComplianceCase::factory()->create([
            'opened_by' => $manager->id, 'assigned_to' => $investigator->id, 'status' => ComplianceCaseStatus::Closed,
        ]);
        try {
            app(ComplianceCaseEvidenceManager::class)->submit($investigator, $closed, [
                'summary' => 'Closed case evidence.', 'evidence_attachment_ids' => [$attachment->id],
            ]);
            $this->fail('Expected closed cases to reject new evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }

        $factorySubmission = ComplianceCaseEvidenceSubmission::factory()->create();
        $factoryPayload = $factorySubmission->only([
            'compliance_case_id', 'version', 'summary', 'case_snapshot', 'latest_event_snapshot',
            'evidence_manifest', 'recorded_by', 'actor_snapshot',
        ]);
        $factoryPayload['recorded_at'] = $factorySubmission->recorded_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryPayload)), $factorySubmission->fingerprint);
        $this->assertSame(data_get($factorySubmission->evidence_manifest, '0.sha256'), $factorySubmission->evidence()->value('sha256'));
        $factoryEvidence = $factorySubmission->evidence()->firstOrFail();
        TestResponse::fromBaseResponse(app(FileAccess::class)->streamComplianceCaseEvidence($factorySubmission->actor, $factoryEvidence))
            ->assertOk()->assertStreamedContent('factory compliance evidence');

        $now = now()->startOfSecond();
        foreach (range(2, 100) as $version) {
            $boundPayload = [
                'compliance_case_id' => $case->id, 'version' => $version,
                'summary' => "Bound evidence submission {$version}.", 'case_snapshot' => $submission->case_snapshot,
                'latest_event_snapshot' => $submission->latest_event_snapshot, 'evidence_manifest' => $submission->evidence_manifest,
                'recorded_by' => $investigator->id, 'actor_snapshot' => $investigator->only(['id', 'name', 'email']),
                'recorded_at' => $now->toIso8601String(),
            ];
            ComplianceCaseEvidenceSubmission::factory()->create($boundPayload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($boundPayload)),
            ]);
        }
        $this->assertSame(100, $case->evidenceSubmissions()->count());
        try {
            app(ComplianceCaseEvidenceManager::class)->submit($investigator, $case, [
                'summary' => 'Evidence submission 101.', 'evidence_attachment_ids' => [$attachment->id],
            ]);
            $this->fail('Expected the exact 100-submission bound to reject submission 101.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $this->assertSame(100, $case->evidenceSubmissions()->count());

        $operatorSubmission = $submission;
        $investigator->givePermissionTo('Manage Compliance Cases');
        $operatorSubmission->load(['evidence.attachment.audit.members', 'evidence.attachment.dataRequestResponse.dataRequest.audit.members']);
        $eligibleProjection = app(ComplianceCaseEvidenceManager::class)->visibleSubmissions(collect([$operatorSubmission]), $investigator)->first();
        $ineligibleProjection = app(ComplianceCaseEvidenceManager::class)->visibleSubmissions(collect([$operatorSubmission]), $reader)->first();
        $this->assertCount(1, $eligibleProjection->evidence);
        $this->assertCount(0, $ineligibleProjection->evidence);
        $this->assertStringContainsString($evidence->sha256, view('filament.compliance-case-evidence', ['record' => $eligibleProjection])->render());
        $this->assertStringNotContainsString($evidence->sha256, view('filament.compliance-case-evidence', ['record' => $ineligibleProjection])->render());
        Livewire::actingAs($reader);
        Livewire::test(EvidenceSubmissionsRelationManager::class, ['ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class])
            ->assertCanSeeTableRecords([$operatorSubmission])->assertTableActionVisible('inspect', $operatorSubmission)
            ->mountTableAction('inspect', $operatorSubmission)->assertDontSee($evidence->file_name_snapshot)->assertDontSee($evidence->sha256);
        $migration = require database_path('migrations/2026_08_25_070000_create_compliance_case_evidence_submissions.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('compliance_case_evidence_submissions', ['id' => $submission->id, 'fingerprint' => $submission->fingerprint]);
    }

    public function test_case_team_governs_bounded_interviews_with_reconstructible_terminal_history(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $outsider = User::factory()->create();
        $subject = User::factory()->create();
        $caseManager = app(ComplianceCaseManager::class);
        $interviews = app(ComplianceCaseInterviewManager::class);
        $case = $caseManager->open($manager, [
            'title' => 'Interview-governed inquiry', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A deliberate allegation requiring interviews.',
            'summary' => 'Open the governed case.',
        ]);
        $caseManager->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Interview fact gathering is required.', 'summary' => 'Assign the investigator.',
        ]);
        $this->approveInvestigationPlan($case->refresh(), $investigator, $manager);
        $caseManager->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'Interview work is underway.', 'summary' => 'Begin investigation.',
        ]);
        $this->completeInvestigationPlan($case->refresh(), $investigator);

        try {
            $interviews->schedule($outsider, $case, ['interviewer_id' => PHP_INT_MAX]);
            $this->fail('Expected authorization before interview payload validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $scheduledAt = now()->addDay()->startOfSecond();
        $response = $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/interviews", [
            'subject_user_id' => $subject->id, 'interviewer_id' => $investigator->id,
            'scheduled_at' => $scheduledAt->toIso8601String(), 'location' => 'Secure room',
            'purpose' => 'Ask the witness about the deliberate allegation.', 'rationale' => 'Schedule attributable fact gathering.',
            'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->assertNull($response->json('data.id'));
        $interviewId = $this->postJson("/api/compliance-cases/{$case->id}/interviews", [
            'subject_user_id' => $subject->id, 'interviewer_id' => $investigator->id,
            'scheduled_at' => $scheduledAt->toIso8601String(), 'location' => 'Secure room',
            'purpose' => 'Ask the witness about the deliberate allegation.', 'rationale' => 'Schedule attributable fact gathering.',
        ])->assertCreated()->assertJsonPath('data.status', ComplianceCaseInterviewStatus::Scheduled->value)->json('data.id');
        $interview = ComplianceCaseInterview::query()->findOrFail($interviewId);
        $this->approveInvestigationReport($case->refresh(), $investigator);
        try {
            $caseManager->record($manager, $case->refresh(), [
                'status' => ComplianceCaseStatus::Resolved->value,
                'resolution_summary' => 'Premature resolution.', 'summary' => 'Attempt resolution with a scheduled interview.',
            ]);
            $this->fail('Expected scheduled interviews to block case resolution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->actingAs($outsider)->getJson("/api/compliance-cases/{$case->id}/interviews")->assertForbidden();
        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}/interviews?per_page=1")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.events.0.event_type', 'scheduled');

        $conductedAt = now()->startOfSecond();
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/interviews/{$interview->id}/events", [
            'status' => ComplianceCaseInterviewStatus::Conducted->value, 'conducted_at' => now()->addMinute()->toIso8601String(),
            'summary' => 'A future interview cannot already have occurred.', 'rationale' => 'Reject future actual time.',
        ])->assertUnprocessable()->assertJsonValidationErrors('conducted_at');
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/interviews/{$interview->id}/events", [
            'status' => ComplianceCaseInterviewStatus::Conducted->value, 'conducted_at' => $conductedAt->toIso8601String(),
            'summary' => 'The subject supplied a deliberate account; no truth determination is inferred.',
            'rationale' => 'Retain the interview outcome.',
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.event_type', 'conducted')
            ->assertJsonPath('data.after_snapshot.summary', 'The subject supplied a deliberate account; no truth determination is inferred.');
        $event = $interview->events()->reorder()->latest('version')->firstOrFail();
        $payload = $event->only(['compliance_case_interview_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot', 'rationale', 'recorded_by']);
        $payload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($payload)), $event->fingerprint);
        $this->assertSame($subject->email, data_get($event->after_snapshot, 'subject.email'));
        $this->assertSame($investigator->email, data_get($event->after_snapshot, 'interviewer.email'));
        $this->assertSame('The subject supplied a deliberate account; no truth determination is inferred.', data_get($event->after_snapshot, 'summary'));
        $this->assertThrows(fn () => $event->update(['rationale' => 'Rewrite']), \LogicException::class);
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/interviews/{$interview->id}/events", [
            'status' => ComplianceCaseInterviewStatus::Cancelled->value, 'cancellation_reason' => 'Rewrite terminal state.',
            'rationale' => 'Invalid terminal rewrite.',
        ])->assertUnprocessable()->assertJsonValidationErrors('interview');

        $factoryInterview = ComplianceCaseInterview::factory()->create([
            'compliance_case_id' => $case->id, 'subject_user_id' => $subject->id,
        ]);
        $this->assertTrue($factoryInterview->interviewer->can('Investigate Compliance Cases'));
        $factoryEvent = ComplianceCaseInterviewEvent::factory()->create([
            'compliance_case_interview_id' => $factoryInterview->id, 'recorded_by' => $factoryInterview->interviewer_id,
        ]);
        $factoryPayload = $factoryEvent->only(['compliance_case_interview_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot', 'rationale', 'recorded_by']);
        $factoryPayload['recorded_at'] = $factoryEvent->recorded_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryPayload)), $factoryEvent->fingerprint);

        Livewire::actingAs($reader);
        Livewire::test(InterviewsRelationManager::class, ['ownerRecord' => $case->refresh(), 'pageClass' => ViewComplianceCase::class])
            ->assertCanSeeTableRecords([$interview, $factoryInterview])->assertTableActionVisible('history', $interview);
        $rendered = view('filament.compliance-case-interview-history', [
            'interview' => $interview->fresh()->load(['subjectUser:id,name,email', 'interviewer:id,name,email', 'events.actor:id,name,email']),
        ])->render();
        $this->assertStringContainsString($event->fingerprint, $rendered);
        $this->assertStringContainsString($subject->name, $rendered);

        $migration = require database_path('migrations/2026_08_25_090000_create_compliance_case_interviews.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('compliance_case_interview_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);
    }

    public function test_compliance_case_interview_and_event_bounds_are_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $subject = User::factory()->create();
        $case = ComplianceCase::factory()->create([
            'opened_by' => $manager->id, 'assigned_to' => $investigator->id,
            'status' => ComplianceCaseStatus::Investigating, 'investigation_summary' => 'Bound test.',
        ]);
        foreach (range(1, 100) as $number) {
            $interview = ComplianceCaseInterview::factory()->create([
                'compliance_case_id' => $case->id, 'subject_user_id' => $subject->id,
                'interviewer_id' => $investigator->id, 'scheduled_at' => now()->addDays($number),
            ]);
            ComplianceCaseInterviewEvent::factory()->create([
                'compliance_case_interview_id' => $interview->id, 'recorded_by' => $manager->id,
            ]);
        }
        $this->assertSame(100, $case->interviews()->count());
        try {
            app(ComplianceCaseInterviewManager::class)->schedule($manager, $case, [
                'subject_user_id' => $subject->id, 'interviewer_id' => $investigator->id,
                'scheduled_at' => now()->addYear(), 'purpose' => 'Interview 101.', 'rationale' => 'Exceed bound.',
            ]);
            $this->fail('Expected interview 101 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }

        $bounded = $case->interviews()->firstOrFail();
        foreach (range(2, 20) as $version) {
            ComplianceCaseInterviewEvent::factory()->create([
                'compliance_case_interview_id' => $bounded->id, 'recorded_by' => $manager->id, 'version' => $version,
            ]);
        }
        $this->assertSame(20, $bounded->events()->count());
        try {
            app(ComplianceCaseInterviewManager::class)->record($manager, $case, $bounded, [
                'scheduled_at' => now()->addYears(2), 'rationale' => 'Event 21.',
            ]);
            $this->fail('Expected interview event 21 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('interview', $exception->errors());
        }
        $this->assertSame(20, $bounded->events()->count());
    }

    public function test_governed_legal_holds_require_exact_custodian_acknowledgement_and_independent_release_before_closure(): void
    {
        $issuer = User::factory()->create();
        $issuer->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $releaser = User::factory()->create();
        $releaser->assignRole('Security Admin');
        $custodianOne = User::factory()->create();
        $custodianTwo = User::factory()->create();
        $outsider = User::factory()->create();
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $cases = app(ComplianceCaseManager::class);
        $holds = app(ComplianceCaseLegalHoldManager::class);
        $case = $cases->open($issuer, [
            'title' => 'Preservation-governed inquiry', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::Critical->value,
            'allegation' => 'A deliberate allegation requires internal preservation instructions.',
            'summary' => 'Open the governed case without asserting the allegation is true.',
        ]);

        try {
            $holds->issue($outsider, $case, ['custodian_ids' => [PHP_INT_MAX]]);
            $this->fail('Expected current case authorization before legal-hold validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $holds->issue($issuer, $case, [
                'scope' => 'Invalid empty source scope.', 'systems' => ['   '], 'data_categories' => ["\t"],
                'preservation_start_at' => now(), 'custodian_ids' => [$custodianOne->id],
            ]);
            $this->fail('Expected canonical empty system/category values to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('systems.0', $exception->errors());
        }
        try {
            $holds->issue($issuer, $case, [
                'scope' => 'Invalid empty category scope.', 'systems' => ['Email'], 'data_categories' => ['   '],
                'preservation_start_at' => now(), 'custodian_ids' => [$custodianOne->id],
            ]);
            $this->fail('Expected canonical empty data categories to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data_categories.0', $exception->errors());
        }
        $this->assertDatabaseCount('compliance_case_legal_holds', 0);

        $preservationStart = now()->subHour()->startOfSecond();
        $payload = [
            'scope' => 'Preserve correspondence, approvals, and contracting records relevant to the deliberate case scope.',
            'systems' => ['Procurement', 'Email', 'Email'], 'data_categories' => ['Contracts', 'Correspondence'],
            'legal_basis_reference' => 'COUNSEL-2026-85', 'preservation_start_at' => $preservationStart->toIso8601String(),
            'custodian_ids' => [$custodianTwo->id, $custodianOne->id],
        ];
        $this->actingAs($issuer)->postJson("/api/compliance-cases/{$case->id}/legal-holds", $payload + ['fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $holdId = $this->postJson("/api/compliance-cases/{$case->id}/legal-holds", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.systems.0', 'Email')
            ->assertJsonCount(2, 'data.custodians')->json('data.id');
        $hold = ComplianceCaseLegalHold::query()->with($holds->relations())->findOrFail($holdId);
        $issuePayload = $hold->only([
            'compliance_case_id', 'compliance_case_event_id', 'version', 'reference', 'scope', 'systems',
            'data_categories', 'legal_basis_reference', 'issued_by', 'issuer_snapshot', 'case_snapshot',
            'latest_event_snapshot', 'custodian_snapshot',
        ]);
        $issuePayload['preservation_start_at'] = $hold->preservation_start_at->toIso8601String();
        $issuePayload['issued_at'] = $hold->issued_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($issuePayload)), $hold->fingerprint);
        $this->assertSame([$custodianOne->id, $custodianTwo->id], collect($hold->custodian_snapshot)->pluck('id')->all());
        $this->assertSame($case->allegation, data_get($hold->case_snapshot, 'allegation'));

        $this->actingAs($outsider)->getJson('/api/my-compliance-case-legal-holds')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($custodianOne)->getJson('/api/my-compliance-case-legal-holds')->assertOk()
            ->assertJsonPath('data.0.legal_hold.reference', $hold->reference)
            ->assertJsonMissingPath('data.0.legal_hold.case_snapshot')->assertJsonMissingPath('data.0.legal_hold.compliance_case_id');
        $this->actingAs($outsider)->postJson("/api/compliance-case-legal-holds/{$hold->id}/acknowledge", [
            'statement' => 'Unauthorized acknowledgement.',
        ])->assertForbidden();
        $this->actingAs($custodianOne)->postJson("/api/compliance-case-legal-holds/{$hold->id}/acknowledge", [
            'statement' => 'I acknowledge and will follow this preservation instruction.', 'comment' => 'Instruction received.',
        ])->assertCreated()->assertJsonMissingPath('data.hold_snapshot')->assertJsonPath('data.statement', 'I acknowledge and will follow this preservation instruction.');
        $ackOne = ComplianceCaseLegalHoldAcknowledgement::query()->where('user_id', $custodianOne->id)->firstOrFail();
        $ackPayload = $ackOne->only([
            'compliance_case_legal_hold_id', 'compliance_case_legal_hold_custodian_id', 'user_id',
            'hold_snapshot', 'recipient_snapshot', 'statement', 'comment',
        ]) + ['acknowledged_at' => $ackOne->acknowledged_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($ackPayload)), $ackOne->fingerprint);
        $this->actingAs($releaser)->postJson("/api/compliance-cases/{$case->id}/legal-holds/{$hold->id}/release", [
            'summary' => 'Premature release.',
        ])->assertUnprocessable()->assertJsonValidationErrors('hold');
        $this->actingAs($issuer)->postJson("/api/compliance-cases/{$case->id}/legal-holds/{$hold->id}/release", [
            'summary' => 'Issuer self release.',
        ])->assertForbidden();

        $this->actingAs($custodianTwo)->postJson("/api/compliance-case-legal-holds/{$hold->id}/acknowledge", [
            'statement' => 'I acknowledge and will follow this preservation instruction.',
        ])->assertCreated();
        $cases->record($issuer, $case->refresh(), [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'The matter requires governed fact finding.', 'summary' => 'Triage and assign.',
        ]);
        $this->approveInvestigationPlan($case->refresh(), $investigator, $issuer);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'The investigator reviewed the preserved internal record set.', 'summary' => 'Begin investigation.',
        ]);
        $this->completeInvestigationPlan($case->refresh(), $investigator);
        $this->approveInvestigationReport($case->refresh(), $investigator);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'The deliberate investigation response is complete.', 'summary' => 'Resolve the case.',
        ]);
        try {
            $cases->record($releaser, $case->refresh(), [
                'status' => ComplianceCaseStatus::Closed->value, 'closure_summary' => 'Premature closure.',
                'summary' => 'Attempt closure while the hold remains active.',
            ]);
            $this->fail('Expected an active legal hold to block case closure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $releaseId = $this->actingAs($releaser)->postJson("/api/compliance-cases/{$case->id}/legal-holds/{$hold->id}/release", [
            'summary' => 'Every current custodian acknowledged the instruction; independent review supports release.',
        ])->assertCreated()->assertJsonPath('data.released_by', $releaser->id)->json('data.id');
        $release = ComplianceCaseLegalHoldRelease::query()->findOrFail($releaseId);
        $releasePayload = $release->only([
            'compliance_case_legal_hold_id', 'released_by', 'actor_snapshot', 'hold_snapshot',
            'custodian_acknowledgement_snapshot', 'summary',
        ]) + ['released_at' => $release->released_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($releasePayload)), $release->fingerprint);
        $this->assertCount(2, $release->custodian_acknowledgement_snapshot);
        $cases->record($releaser, $case->refresh(), [
            'status' => ComplianceCaseStatus::Closed->value,
            'closure_summary' => 'The case and released preservation instruction were independently reviewed.',
            'summary' => 'Close after legal-hold release.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Closed, $case->fresh()->status);

        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}/legal-holds?per_page=1")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.release.fingerprint', $release->fingerprint)
            ->assertJsonPath('data.0.case_snapshot.allegation', $case->allegation);
        Livewire::actingAs($reader);
        Livewire::test(LegalHoldsRelationManager::class, ['ownerRecord' => $case->refresh(), 'pageClass' => ViewComplianceCase::class])
            ->assertCanSeeTableRecords([$hold])->assertTableActionVisible('inspect', $hold);
        $renderedHold = view('filament.compliance-case-legal-hold', [
            'record' => $hold->fresh()->load($holds->relations()),
        ])->render();
        $this->assertStringContainsString($release->fingerprint, $renderedHold);
        $this->assertStringContainsString($custodianOne->name, $renderedHold);
        $this->assertStringContainsString('COUNSEL-2026-85', $renderedHold);
        $this->assertStringContainsString('Instruction received.', $renderedHold);
        $this->assertStringContainsString($case->allegation, $renderedHold);
        $this->assertThrows(fn () => $hold->update(['scope' => 'Rewrite']), \LogicException::class);
        $this->assertThrows(fn () => $ackOne->delete(), \LogicException::class);
        $this->assertThrows(fn () => $release->update(['summary' => 'Rewrite']), \LogicException::class);
    }

    public function test_legal_hold_factories_bounds_and_retained_migration_are_coherent(): void
    {
        $factoryHold = ComplianceCaseLegalHold::factory()->create();
        $factoryIssuePayload = $factoryHold->only([
            'compliance_case_id', 'compliance_case_event_id', 'version', 'reference', 'scope', 'systems',
            'data_categories', 'legal_basis_reference', 'issued_by', 'issuer_snapshot', 'case_snapshot',
            'latest_event_snapshot', 'custodian_snapshot',
        ]) + [
            'preservation_start_at' => $factoryHold->preservation_start_at->toIso8601String(),
            'issued_at' => $factoryHold->issued_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryIssuePayload)), $factoryHold->fingerprint);
        $this->assertSame(1, $factoryHold->custodians()->count());
        $factoryAcknowledgement = ComplianceCaseLegalHoldAcknowledgement::factory()->create();
        $factoryAckPayload = $factoryAcknowledgement->only([
            'compliance_case_legal_hold_id', 'compliance_case_legal_hold_custodian_id', 'user_id',
            'hold_snapshot', 'recipient_snapshot', 'statement', 'comment',
        ]) + ['acknowledged_at' => $factoryAcknowledgement->acknowledged_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryAckPayload)), $factoryAcknowledgement->fingerprint);
        $factoryRelease = ComplianceCaseLegalHoldRelease::factory()->create();
        $factoryReleasePayload = $factoryRelease->only([
            'compliance_case_legal_hold_id', 'released_by', 'actor_snapshot', 'hold_snapshot',
            'custodian_acknowledgement_snapshot', 'summary',
        ]) + ['released_at' => $factoryRelease->released_at->toIso8601String()];
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryReleasePayload)), $factoryRelease->fingerprint);

        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $custodian = User::factory()->create();
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Legal hold bound', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A deliberate bound test.',
            'summary' => 'Open bound-test case.',
        ]);
        foreach (range(1, 20) as $version) {
            app(ComplianceCaseLegalHoldManager::class)->issue($manager, $case, [
                'scope' => "Governed preservation instruction {$version}.", 'systems' => ['Email'],
                'data_categories' => ['Correspondence'], 'preservation_start_at' => now(),
                'custodian_ids' => [$custodian->id],
            ]);
        }
        $this->assertSame(20, $case->legalHolds()->count());
        try {
            app(ComplianceCaseLegalHoldManager::class)->issue($manager, $case, [
                'scope' => 'Hold 21.', 'systems' => ['Email'], 'data_categories' => ['Correspondence'],
                'preservation_start_at' => now(), 'custodian_ids' => [$custodian->id],
            ]);
            $this->fail('Expected legal hold 21 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $this->assertSame(20, $case->legalHolds()->count());

        $migration = require database_path('migrations/2026_08_25_100000_create_compliance_case_legal_holds.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('compliance_case_legal_holds', ['id' => $factoryHold->id, 'fingerprint' => $factoryHold->fingerprint]);
        $this->assertDatabaseHas('compliance_case_legal_hold_acknowledgements', ['id' => $factoryAcknowledgement->id]);
        $this->assertDatabaseHas('compliance_case_legal_hold_releases', ['id' => $factoryRelease->id]);
    }

    private function acceptedEvidence(User $auditManager, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $auditManager->id]);
        $request = DataRequest::factory()->create(['audit_id' => $audit->id, 'created_by_id' => $auditManager->id, 'assigned_to_id' => $auditManager->id]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $auditManager->id, 'requestee_id' => $auditManager->id,
        ]);

        return FileAttachment::factory()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id, 'file_name' => basename($path),
            'file_path' => $path, 'file_size' => strlen($contents), 'description' => 'Governed compliance-case evidence',
            'uploaded_by' => $auditManager->id,
        ]);
    }

    private function approveInvestigationPlan(ComplianceCase $case, User $investigator, User $reviewer): void
    {
        $service = app(ComplianceCaseInvestigationPlanManager::class);
        $plan = $service->submit($investigator, $case, [
            'objectives' => ['Establish the material facts'], 'scope' => 'The allegation and directly related records.',
            'procedures' => ['Inspect relevant records', 'Conduct required interviews'],
            'target_completion_at' => now()->addMonth()->toDateString(), 'rationale' => 'Define the governed investigation approach.',
        ]);
        $service->review($reviewer, $plan, [
            'decision' => ComplianceCaseInvestigationPlanDecision::Approved->value,
            'summary' => 'The plan is proportionate and ready for execution.',
        ]);
    }

    private function completeInvestigationPlan(ComplianceCase $case, User $investigator): void
    {
        $plan = $case->investigationPlans()->latest('version')->firstOrFail();
        $service = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Manage Compliance Cases');
        foreach ($plan->procedures as $offset => $procedure) {
            $execution = $service->record($investigator, $case->refresh(), [
                'procedure_index' => $offset + 1, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
                'summary' => "Completed: {$procedure}", 'findings' => 'Retained test conclusion.',
            ]);
            $service->review($reviewer, $execution, [
                'decision' => 'approved', 'summary' => 'The retained procedure conclusion is approved.',
            ]);
        }
    }

    private function approveInvestigationReport(ComplianceCase $case, User $investigator): void
    {
        $service = app(ComplianceCaseInvestigationReportManager::class);
        $report = $service->submit($investigator, $case->refresh(), [
            'outcome' => 'substantiated', 'executive_summary' => 'The configured investigation work is complete.',
            'analysis' => 'The retained procedure conclusions were synthesized.', 'findings' => 'Retained test report findings.',
            'recommendations' => 'Apply the governed resolution decision.',
        ]);
        $reportReviewer = User::factory()->create();
        $reportReviewer->givePermissionTo('Manage Compliance Cases');
        $service->review($reportReviewer, $report, [
            'decision' => 'approved', 'summary' => 'The test investigation report is approved.',
        ]);
    }
}
