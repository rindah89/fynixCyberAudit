<?php

namespace Tests\Feature;

use App\Access\FileAccess;
use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EvidenceSubmissionsRelationManager;
use App\Models\Audit;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
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
        $service->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'Interviews and procurement records are being reviewed.',
            'summary' => 'Begin the assigned investigation.',
        ]);
        $service->record($resolutionManager, $case->refresh(), [
            'investigation_summary' => 'The independent case manager added a material investigation conclusion.',
            'summary' => 'Add a material investigation decision without changing status.',
        ]);
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
}
