<?php

namespace Tests\Feature;

use App\Enums\IncidentPhase;
use App\Enums\IncidentTaskStatus;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\PhaseTransitionsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\TasksRelationManager;
use App\Incidents\IncidentDesk;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Incident;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPlaybook;
use App\Models\IncidentPlaybookTask;
use App\Models\IncidentTaskEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.incidents', true);
    }

    public function test_playbook_seeds_tasks_on_create(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('Security Admin');

        $playbook = IncidentPlaybook::factory()->create([
            'name' => 'Ransomware',
            'incident_type' => 'Malware',
        ]);
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id,
            'title' => 'Isolate affected hosts',
            'phase' => IncidentPhase::Containment->value,
        ]);
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id,
            'title' => 'Confirm ransomware family',
            'phase' => IncidentPhase::Identification->value,
        ]);

        $incident = app(IncidentDesk::class)->createFromPlaybook($lead, $playbook, [
            'title' => 'Finance NAS encrypted',
            'severity' => 'High',
        ]);

        $this->assertMatchesRegularExpression('/^INC-\d{4}-\d{4}$/', $incident->number);
        $this->assertSame('Open', $incident->status);
        $this->assertSame(IncidentPhase::Identification, $incident->phase);
        $this->assertCount(2, $incident->tasks);
        $this->assertTrue($incident->tasks->contains('title', 'Isolate affected hosts'));
        $this->assertTrue($incident->tasks->contains('title', 'Confirm ransomware family'));
    }

    public function test_phase_advance_is_timestamped_and_cannot_reverse(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('Security Admin');
        $playbook = IncidentPlaybook::factory()->create();
        $incident = app(IncidentDesk::class)->createFromPlaybook($lead, $playbook, [
            'title' => 'Phishing campaign',
            'severity' => 'Medium',
        ]);

        $advanced = app(IncidentDesk::class)->advancePhase($lead, $incident, IncidentPhase::Containment);

        $this->assertSame(IncidentPhase::Containment, $advanced->phase);
        $this->assertNotNull($advanced->phaseTimestamp(IncidentPhase::Containment));

        try {
            app(IncidentDesk::class)->advancePhase($lead, $advanced, IncidentPhase::Identification);
            $this->fail('Expected phase reverse to be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot reverse', strtolower($e->getMessage()));
        }

        $this->assertSame(IncidentPhase::Containment, $advanced->fresh()->phase);
    }

    public function test_evidence_stores_a_content_hash(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('Security Admin');
        $playbook = IncidentPlaybook::factory()->create();
        $incident = app(IncidentDesk::class)->createFromPlaybook($lead, $playbook, [
            'title' => 'Laptop theft',
            'severity' => 'High',
        ]);

        $contents = "chain of custody note\nseized image";
        $evidence = app(IncidentDesk::class)->storeEvidence($lead, $incident, $contents, 'seized.txt');

        $this->assertSame(hash('sha256', $contents), $evidence->hash);
        $this->assertSame(IncidentPhase::Identification, $evidence->phase);
        $this->assertTrue($evidence->chain_of_custody);
        $this->assertNotEmpty($evidence->path);
    }

    public function test_non_manager_cannot_advance_phase(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Regular User');

        $playbook = IncidentPlaybook::factory()->create();
        $incident = app(IncidentDesk::class)->createFromPlaybook($lead, $playbook, [
            'title' => 'Internal only',
            'severity' => 'Low',
        ]);

        try {
            app(IncidentDesk::class)->advancePhase($outsider, $incident, IncidentPhase::Containment);
            $this->fail('Expected outsider to be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_rest_creation_and_forward_transition_retain_reconstructible_governance_evidence(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $playbook = IncidentPlaybook::factory()->create(['name' => 'Credential compromise']);
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id,
            'title' => 'Disable exposed credentials',
            'phase' => IncidentPhase::Containment,
        ]);

        $created = $this->actingAs($manager)->postJson('/api/incidents', [
            'incident_playbook_id' => $playbook->id,
            'title' => 'Privileged account exposed',
            'severity' => 'Critical',
            'detected_at' => now()->subMinute()->toIso8601String(),
            'involves_data' => true,
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^INC-\d{4}-0001$/', $created['number']);
        $this->assertSame('Credential compromise', $created['playbook_snapshot']['name']);
        $this->assertSame('Disable exposed credentials', $created['playbook_snapshot']['tasks'][0]['title']);
        $this->assertCount(1, $created['phase_transitions']);

        $transitioned = $this->actingAs($manager)->postJson('/api/incidents/'.$created['id'].'/phase-transitions', [
            'phase' => IncidentPhase::Containment->value,
            'summary' => 'Accounts disabled and sessions revoked.',
        ])->assertOk()->json('data');

        $this->assertSame(IncidentPhase::Containment->value, $transitioned['phase']);
        $this->assertCount(2, $transitioned['phase_transitions']);
        $transition = IncidentPhaseTransition::query()->findOrFail($transitioned['phase_transitions'][1]['id']);
        $payload = [
            'incident_id' => $transition->incident_id,
            'from_phase' => $transition->from_phase?->value,
            'to_phase' => $transition->to_phase->value,
            'summary' => $transition->summary,
            'incident_snapshot' => $transition->incident_snapshot,
            'transitioned_by' => $transition->transitioned_by,
            'transitioned_at' => $transition->transitioned_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $transition->fingerprint);
        $this->assertSame('Critical', $transition->incident_snapshot['severity']);

        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $this->actingAs($reader)->getJson('/api/incidents/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.phase_transitions.1.summary', 'Accounts disabled and sessions revoked.');
        Livewire::actingAs($reader);
        Livewire::test(PhaseTransitionsRelationManager::class, [
            'ownerRecord' => Incident::query()->findOrFail($created['id']),
            'pageClass' => ViewIncident::class,
        ])->assertCanSeeTableRecords(IncidentPhaseTransition::query()->get());

        $migration = require database_path('migrations/2026_08_24_560000_create_governed_incident_transitions.php');
        $migration->down();
        $this->assertDatabaseHas('incident_phase_transitions', ['id' => $transition->id, 'fingerprint' => $transition->fingerprint]);
    }

    public function test_incident_interfaces_reject_server_fields_invalid_jumps_and_direct_service_bypass(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Regular User');
        $playbook = IncidentPlaybook::factory()->create();

        $this->actingAs($manager)->postJson('/api/incidents', [
            'incident_playbook_id' => $playbook->id,
            'title' => 'Caller-owned state', 'severity' => 'High', 'detected_at' => now()->toIso8601String(),
            'number' => 'INC-2000-9999', 'phase' => IncidentPhase::Recovery->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['number', 'phase']);

        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, $playbook, ['title' => 'Bounded incident']);
        try {
            app(IncidentDesk::class)->advancePhase($manager, $incident, IncidentPhase::Recovery, 'Skipped phases.');
            $this->fail('Expected a skipped response phase to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly one phase', $exception->getMessage());
        }
        $this->assertSame(IncidentPhase::Identification, $incident->fresh()->phase);
        $this->assertCount(1, $incident->phaseTransitions);

        try {
            app(IncidentDesk::class)->advancePhase($outsider, $incident, IncidentPhase::Containment, 'Unauthorized transition.');
            $this->fail('Expected direct service authorization to reject the transition.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('incident_phase_transitions', 1);

        $transition = $incident->phaseTransitions()->firstOrFail();
        $this->expectException(RuntimeException::class);
        $transition->update(['summary' => 'Rewritten']);
    }

    public function test_legacy_incidents_are_visible_but_cannot_enter_governed_phase_history(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $legacy = Incident::query()->create([
            'number' => 'INC-2025-0042', 'title' => 'Imported legacy incident',
            'severity' => 'High', 'status' => 'Open', 'phase' => IncidentPhase::Identification,
            'lead_id' => $manager->id, 'reporter_id' => $manager->id, 'detected_at' => now()->subYear(),
        ]);

        $this->actingAs($manager)->getJson('/api/incidents/'.$legacy->id)
            ->assertOk()
            ->assertJsonPath('data.governance_status', 'legacy')
            ->assertJsonCount(0, 'data.phase_transitions');

        try {
            app(IncidentDesk::class)->advancePhase($manager, $legacy, IncidentPhase::Containment, 'Attempted onboarding.');
            $this->fail('Expected legacy incident transition to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('incident_phase_transitions', 0);
        $legacyTask = $legacy->tasks()->create([
            'title' => 'Imported task', 'phase' => IncidentPhase::Identification,
            'status' => IncidentTaskStatus::Open->value, 'priority' => 'Medium',
        ]);
        try {
            app(IncidentDesk::class)->recordTaskEvent($manager, $legacyTask, [
                'status' => IncidentTaskStatus::InProgress->value, 'summary' => 'Attempted legacy task transition.',
            ]);
            $this->fail('Expected legacy task event to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('incident_task_events', 0);
    }

    public function test_governance_migration_repairs_a_missing_snapshot_column(): void
    {
        Schema::table('incidents', fn ($table) => $table->dropColumn('playbook_snapshot'));
        $this->assertFalse(Schema::hasColumn('incidents', 'playbook_snapshot'));

        $migration = require database_path('migrations/2026_08_24_560000_create_governed_incident_transitions.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('incidents', 'incident_playbook_id'));
        $this->assertTrue(Schema::hasColumn('incidents', 'playbook_snapshot'));
        $this->assertTrue(Schema::hasColumn('incidents', 'governed_at'));
    }

    public function test_governed_response_tasks_retain_assignment_and_execution_history(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $responder = User::factory()->create();
        $outsider = User::factory()->create();
        $playbook = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id, 'title' => 'Preserve volatile evidence',
            'phase' => IncidentPhase::Identification, 'priority' => 'High',
        ]);
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, $playbook, ['title' => 'Endpoint compromise']);
        $task = $incident->tasks()->firstOrFail();
        $this->assertSame('governed', $task->governance_status);
        $this->assertDatabaseHas('incident_task_events', ['incident_task_id' => $task->id, 'version' => 1, 'event_type' => 'seeded']);

        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::InProgress->value,
            'assignee_id' => $responder->id,
            'due_date' => now()->addDay()->toDateString(),
            'summary' => 'Assigned collection to the endpoint responder.',
        ])->assertCreated()->assertJsonPath('task.status', IncidentTaskStatus::InProgress->value)
            ->assertJsonPath('task.assignee.id', $responder->id);

        $completed = $this->actingAs($responder)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::Completed->value,
            'summary' => 'Memory and volatile network state captured.',
        ])->assertCreated()->json('data');
        $this->assertSame(3, $completed['version']);
        $this->assertSame($responder->id, $completed['recorded_by']);

        $event = IncidentTaskEvent::query()->findOrFail($completed['id']);
        $payload = [
            'incident_id' => $event->incident_id, 'incident_task_id' => $event->incident_task_id,
            'version' => $event->version, 'event_type' => $event->event_type,
            'from_status' => $event->from_status?->value, 'to_status' => $event->to_status->value,
            'before_snapshot' => $event->before_snapshot, 'after_snapshot' => $event->after_snapshot,
            'evidence_manifest' => $event->evidence_manifest,
            'summary' => $event->summary, 'recorded_by' => $event->recorded_by,
            'recorded_at' => $event->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $event->fingerprint);
        try {
            $event->update(['summary' => 'Rewritten event']);
            $this->fail('Expected task event history to be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->actingAs($outsider)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::Blocked->value, 'summary' => 'Unauthorized.',
        ])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::Cancelled->value, 'summary' => 'Rewrite terminal state.', 'version' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->actingAs($manager)->getJson('/api/incidents/'.$incident->id)
            ->assertOk()->assertJsonPath('data.tasks.0.events_count', 3)
            ->assertJsonMissingPath('data.tasks.0.events');
        $this->actingAs($manager)->getJson('/api/incident-tasks/'.$task->id.'/events?per_page=2')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.after_snapshot.status', IncidentTaskStatus::InProgress->value);
        Livewire::actingAs($manager);
        Livewire::test(TasksRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$task])->assertTableActionVisible('inspect_history', $task);

        $migration = require database_path('migrations/2026_08_24_570000_create_governed_incident_task_events.php');
        $migration->down();
        $this->assertDatabaseHas('incident_task_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);
    }

    public function test_task_phase_assignee_authority_and_terminal_state_are_enforced(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $responder = User::factory()->create();
        $inactive = User::factory()->create();
        $playbook = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id, 'title' => 'Restore service',
            'phase' => IncidentPhase::Recovery, 'priority' => 'High',
        ]);
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, $playbook, ['title' => 'Recovery controls']);
        $task = $incident->tasks()->firstOrFail();

        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::InProgress->value, 'summary' => 'Started before recovery.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'assignee_id' => $responder->id, 'summary' => 'Assigned recovery owner.',
        ])->assertCreated();
        $this->actingAs($responder)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'assignee_id' => $manager->id, 'summary' => 'Attempted reassignment.',
        ])->assertForbidden();

        $inactive->delete();
        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'assignee_id' => $inactive->id, 'summary' => 'Attempted inactive assignment.',
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');

        foreach ([IncidentPhase::Containment, IncidentPhase::Eradication, IncidentPhase::Recovery] as $phase) {
            app(IncidentDesk::class)->advancePhase($manager, $incident->refresh(), $phase, 'Advanced response phase.');
        }
        app(IncidentDesk::class)->recordTaskEvent($manager, $task, [
            'status' => IncidentTaskStatus::InProgress->value, 'summary' => 'Recovery work started.',
        ]);
        app(IncidentDesk::class)->recordTaskEvent($responder, $task, [
            'status' => IncidentTaskStatus::Completed->value, 'summary' => 'Recovery work completed.',
        ]);

        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'due_date' => now()->addWeek()->toDateString(), 'summary' => 'Attempted terminal rewrite.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(IncidentTaskStatus::Completed->value, $task->fresh()->status);
    }

    public function test_incident_task_and_event_history_bounds_are_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $maximum = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->count(100)->create(['incident_playbook_id' => $maximum->id]);
        $maximumIncident = app(IncidentDesk::class)->createFromPlaybook($manager, $maximum, ['title' => 'Maximum governed playbook']);
        $this->assertSame(100, $maximumIncident->tasks()->count());
        $this->assertSame(100, IncidentTaskEvent::query()->where('incident_id', $maximumIncident->id)->count());

        $oversized = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->count(101)->create(['incident_playbook_id' => $oversized->id]);
        try {
            app(IncidentDesk::class)->createFromPlaybook($manager, $oversized, ['title' => 'Oversized playbook']);
            $this->fail('Expected the governed task bound to reject the playbook.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident_playbook_id', $exception->errors());
        }
        $this->assertDatabaseCount('incidents', 1);

        $playbook = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id, 'phase' => IncidentPhase::Identification,
        ]);
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, $playbook, ['title' => 'Bounded events']);
        $task = $incident->tasks()->firstOrFail();
        app(IncidentDesk::class)->recordTaskEvent($manager, $task, [
            'status' => IncidentTaskStatus::InProgress->value, 'summary' => 'Started.',
        ]);
        for ($version = 3; $version <= 100; $version++) {
            $next = $version % 2 === 1 ? IncidentTaskStatus::Blocked : IncidentTaskStatus::InProgress;
            app(IncidentDesk::class)->recordTaskEvent($manager, $task, [
                'status' => $next->value, 'summary' => 'Bounded event '.$version.'.',
            ]);
        }
        $this->assertSame(100, $task->events()->count());
        try {
            app(IncidentDesk::class)->recordTaskEvent($manager, $task, [
                'status' => IncidentTaskStatus::Blocked->value, 'summary' => 'One event too many.',
            ]);
            $this->fail('Expected the governed event bound to reject the event.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame(100, $task->events()->count());
    }

    public function test_task_event_can_bind_retained_acl_scoped_accepted_evidence_atomically(): void
    {
        Storage::fake('private');
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Regular User');
        $playbook = IncidentPlaybook::factory()->create();
        IncidentPlaybookTask::factory()->create([
            'incident_playbook_id' => $playbook->id, 'phase' => IncidentPhase::Identification,
        ]);
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, $playbook, ['title' => 'Evidence-backed response']);
        $task = $incident->tasks()->firstOrFail();
        $authorized = $this->acceptedEvidence($manager, 'incidents/authorized.txt', 'original response bytes');
        $foreign = $this->acceptedEvidence($outsider, 'incidents/foreign.txt', 'foreign response bytes');

        $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::InProgress->value,
            'evidence_attachment_ids' => [$authorized->id, $foreign->id],
            'summary' => 'Mixed evidence must fail atomically.',
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');
        $this->assertSame(IncidentTaskStatus::Open->value, $task->fresh()->status);
        $this->assertDatabaseCount('incident_task_event_evidence', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('governed-evidence/incident-task-event'));

        $response = $this->actingAs($manager)->postJson('/api/incident-tasks/'.$task->id.'/events', [
            'status' => IncidentTaskStatus::InProgress->value,
            'evidence_attachment_ids' => [$authorized->id],
            'summary' => 'Responder validated containment evidence.',
        ])->assertCreated()->assertJsonCount(1, 'data.evidence');
        $event = IncidentTaskEvent::query()->findOrFail($response->json('data.id'));
        $evidence = $event->evidence()->firstOrFail();
        $this->assertSame(hash('sha256', 'original response bytes'), $evidence->sha256);
        Storage::disk('private')->assertExists($evidence->file_path_snapshot);
        $fingerprintPayload = [
            'incident_id' => $event->incident_id,
            'incident_task_id' => $event->incident_task_id,
            'version' => $event->version,
            'event_type' => $event->event_type,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'before_snapshot' => $event->before_snapshot,
            'after_snapshot' => $event->after_snapshot,
            'evidence_manifest' => $event->evidence_manifest,
            'summary' => $event->summary,
            'recorded_by' => $event->recorded_by,
            'recorded_at' => $event->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $event->fingerprint);

        Storage::disk('private')->put($authorized->file_path, 'replacement source bytes');
        $download = $this->actingAs($manager)->get(route('incident-task-event-evidence.download', $evidence))->assertOk();
        $this->assertSame('original response bytes', $download->streamedContent());
        $this->actingAs($outsider)->getJson('/api/incident-tasks/'.$task->id.'/events')
            ->assertOk()->assertJsonCount(0, 'data.1.evidence');
        $this->actingAs($outsider)->get(route('incident-task-event-evidence.download', $evidence))->assertForbidden();

        try {
            $authorized->delete();
            $this->fail('Expected governed source attachment deletion to be blocked.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('governed evidence', $exception->getMessage());
        }

        $migration = require database_path('migrations/2026_08_24_580000_create_incident_task_event_evidence.php');
        $migration->down();
        $this->assertDatabaseHas('incident_task_event_evidence', ['id' => $evidence->id, 'sha256' => $evidence->sha256]);
    }

    private function acceptedEvidence(User $actor, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $actor->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id, 'created_by_id' => $actor->id, 'assigned_to_id' => $actor->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $actor->id, 'requestee_id' => $actor->id,
        ]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => basename($path), 'file_path' => $path, 'file_size' => strlen($contents),
            'description' => 'Governed incident task evidence', 'uploaded_by' => $actor->id,
        ]);
    }
}
