<?php

namespace Tests\Feature;

use App\Enums\IncidentAffectedEntityType;
use App\Enums\IncidentLessonArea;
use App\Enums\IncidentLessonStatus;
use App\Enums\IncidentNotificationAudience;
use App\Enums\IncidentNotificationStatus;
use App\Enums\IncidentPhase;
use App\Enums\IncidentTaskStatus;
use App\Enums\IncidentTimelineEntryType;
use App\Enums\IncidentTimelineVisibility;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\AffectedEntitiesRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\FinalReportsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\LessonsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\NotificationsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\PhaseTransitionsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\TasksRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\TimelineEntriesRelationManager;
use App\Incidents\IncidentAffectedEntityManager;
use App\Incidents\IncidentDesk;
use App\Incidents\IncidentFinalReportManager;
use App\Incidents\IncidentLessonManager;
use App\Incidents\IncidentNotificationManager;
use App\Incidents\IncidentTimelineManager;
use App\Models\Asset;
use App\Models\Audit;
use App\Models\Control;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Incident;
use App\Models\IncidentFinalReport;
use App\Models\IncidentLesson;
use App\Models\IncidentNotification;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPhaseTransitionEvidence;
use App\Models\IncidentPlaybook;
use App\Models\IncidentPlaybookTask;
use App\Models\IncidentTaskEvent;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

    public function test_phase_decision_can_bind_retained_acl_scoped_accepted_evidence_atomically(): void
    {
        Storage::fake('private');
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Evidence-backed phase decision', 'severity' => 'High',
        ]);
        $authorized = $this->acceptedEvidence($manager, 'incidents/phase-authorized.txt', 'original phase decision bytes');
        $foreign = $this->acceptedEvidence($reader, 'incidents/phase-foreign.txt', 'foreign phase bytes');

        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/phase-transitions', [
            'phase' => IncidentPhase::Containment->value,
            'summary' => 'Mixed selection must fail atomically.',
            'evidence_attachment_ids' => [$authorized->id, $foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');
        $this->assertSame(IncidentPhase::Identification, $incident->fresh()->phase);
        $this->assertDatabaseCount('incident_phase_transition_evidence', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('governed-evidence/incident-phase-transition'));

        $response = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/phase-transitions', [
            'phase' => IncidentPhase::Containment->value,
            'summary' => 'Containment was approved against accepted evidence.',
            'evidence_attachment_ids' => [$authorized->id],
        ])->assertOk();
        $transition = IncidentPhaseTransition::query()->findOrFail($response->json('data.phase_transitions.1.id'));
        $evidence = $transition->evidence()->firstOrFail();
        $this->assertInstanceOf(IncidentPhaseTransitionEvidence::class, $evidence);
        $this->assertSame(hash('sha256', 'original phase decision bytes'), $evidence->sha256);
        $payload = [
            'incident_id' => $transition->incident_id,
            'from_phase' => $transition->from_phase?->value,
            'to_phase' => $transition->to_phase->value,
            'summary' => $transition->summary,
            'incident_snapshot' => $transition->incident_snapshot,
            'evidence_manifest' => $transition->evidence_manifest,
            'transitioned_by' => $transition->transitioned_by,
            'transitioned_at' => $transition->transitioned_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $transition->fingerprint);

        Storage::disk('private')->put($authorized->file_path, 'replacement phase source bytes');
        $download = $this->actingAs($manager)->get(route('incident-phase-transition-evidence.download', $evidence))->assertOk();
        $this->assertSame('original phase decision bytes', $download->streamedContent());
        $readerResponse = $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id)
            ->assertOk()->assertJsonCount(0, 'data.phase_transitions.1.evidence')
            ->assertJsonMissingPath('data.phase_transitions.1.evidence_manifest');
        $this->assertStringNotContainsString($evidence->file_name_snapshot, $readerResponse->getContent());
        $this->assertStringNotContainsString($evidence->sha256, $readerResponse->getContent());
        $this->assertStringNotContainsString($evidence->file_path_snapshot, $readerResponse->getContent());
        $this->actingAs($reader)->get(route('incident-phase-transition-evidence.download', $evidence))->assertForbidden();

        try {
            $evidence->update(['sha256' => str_repeat('0', 64)]);
            $this->fail('Expected phase-decision evidence to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $migration = require database_path('migrations/2026_08_24_610000_create_incident_phase_transition_evidence.php');
        $migration->down();
        $this->assertDatabaseHas('incident_phase_transition_evidence', ['id' => $evidence->id, 'sha256' => $evidence->getOriginal('sha256')]);
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

    public function test_manager_links_immutable_affected_entity_snapshots_for_incident_scope(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $incidentEditor = User::factory()->create();
        $incidentEditor->givePermissionTo('Update Incidents');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Scoped infrastructure incident', 'severity' => 'Critical',
        ]);
        $asset = Asset::factory()->create(['asset_tag' => 'SRV-042', 'name' => 'Payment API node', 'hostname' => 'pay-api-01']);
        $control = Control::factory()->create(['code' => 'IR-04', 'title' => 'Incident containment']);

        $this->actingAs($reader)->postJson('/api/incidents/'.$incident->id.'/affected-entities', [
            'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
            'impact_summary' => 'Unauthorized scope mutation.',
        ])->assertForbidden();
        try {
            app(IncidentAffectedEntityManager::class)->link($reader, $incident, [
                'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
                'impact_summary' => 'Direct service bypass.',
            ]);
            $this->fail('Expected affected-entity service authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(IncidentAffectedEntityManager::class)->link($incidentEditor, $incident, [
                'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
                'impact_summary' => 'Inventory ACL bypass attempt.',
            ]);
            $this->fail('Expected source inventory authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('incident_affected_entities', 0);

        $response = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/affected-entities', [
            'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
            'impact_summary' => 'The payment API node was isolated during containment.',
        ])->assertCreated()->assertJsonPath('data.entity_snapshot.asset_tag', 'SRV-042');
        $record = $incident->affectedEntities()->findOrFail($response->json('data.id'));
        $payload = [
            'incident_id' => $record->incident_id, 'entity_type' => $record->entity_type->value,
            'entity_id_snapshot' => $record->entity_id_snapshot, 'entity_snapshot' => $record->entity_snapshot,
            'impact_summary' => $record->impact_summary, 'control_failure_note' => $record->control_failure_note,
            'linked_by' => $record->linked_by, 'linked_at' => $record->linked_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $record->fingerprint);

        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/affected-entities', [
            'entity_type' => IncidentAffectedEntityType::Control->value, 'entity_id' => $control->id,
            'impact_summary' => 'The containment control was affected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('control_failure_note');
        $controlRecordId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/affected-entities', [
            'entity_type' => IncidentAffectedEntityType::Control->value, 'entity_id' => $control->id,
            'impact_summary' => 'The containment control failed during the response.',
            'control_failure_note' => 'The isolation automation did not cover the affected subnet.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/affected-entities', [
            'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
            'impact_summary' => 'Duplicate immutable scope.',
        ])->assertUnprocessable()->assertJsonValidationErrors('entity_id');

        $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id.'/affected-entities?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
        Livewire::actingAs($reader);
        Livewire::test(AffectedEntitiesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$record, $incident->affectedEntities()->findOrFail($controlRecordId)])
            ->assertTableActionHidden('link');
        $record->load('linkedBy:id,name');
        $rendered = view('filament.incident-affected-entity', ['record' => $record])->render();
        $this->assertStringContainsString('Payment API node', $rendered);
        $this->assertStringContainsString($record->fingerprint, $rendered);

        $originalFingerprint = $record->fingerprint;
        $asset->update(['name' => 'Renamed after incident scope capture']);
        $asset->delete();
        $this->assertSame('Payment API node', data_get($record->fresh()->entity_snapshot, 'name'));
        $this->assertSame($originalFingerprint, $record->fresh()->fingerprint);

        try {
            $record->delete();
            $this->fail('Expected affected-entity evidence to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $migration = require database_path('migrations/2026_08_24_620000_create_incident_affected_entities.php');
        $migration->down();
        $this->assertDatabaseHas('incident_affected_entities', ['id' => $record->id, 'fingerprint' => $record->fingerprint]);
    }

    public function test_affected_entity_history_bound_is_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Bounded affected scope', 'severity' => 'High',
        ]);
        $assets = collect(range(1, 101))->map(fn (int $index) => Asset::factory()->create([
            'asset_tag' => 'BOUND-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'name' => 'Bounded asset '.$index,
        ]));
        $service = app(IncidentAffectedEntityManager::class);
        foreach ($assets->take(100) as $index => $asset) {
            $service->link($manager, $incident, [
                'entity_type' => IncidentAffectedEntityType::Asset->value,
                'entity_id' => $asset->id,
                'impact_summary' => 'Affected asset '.($index + 1).'.',
            ]);
        }
        $this->assertSame(100, $incident->affectedEntities()->count());
        try {
            $service->link($manager, $incident, [
                'entity_type' => IncidentAffectedEntityType::Asset->value,
                'entity_id' => $assets->last()->id,
                'impact_summary' => 'One too many affected assets.',
            ]);
            $this->fail('Expected the affected-entity bound to reject record 101.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertSame(100, $incident->affectedEntities()->count());
    }

    public function test_timeline_is_append_only_and_redacts_internal_entries_from_readers(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Visibility-aware incident timeline', 'severity' => 'High',
        ]);
        $occurredAt = now()->subMinutes(5)->startOfSecond();

        $this->actingAs($reader)->postJson('/api/incidents/'.$incident->id.'/timeline', [
            'entry_type' => IncidentTimelineEntryType::Observation->value,
            'visibility' => IncidentTimelineVisibility::Auditor->value,
            'occurred_at' => $occurredAt->toIso8601String(), 'summary' => 'Unauthorized entry.',
        ])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/timeline', [
            'entry_type' => IncidentTimelineEntryType::Observation->value,
            'visibility' => IncidentTimelineVisibility::Internal->value,
            'occurred_at' => $occurredAt->toIso8601String(), 'summary' => 'Internal responder hypothesis.',
            'details' => 'Unverified technical analysis restricted to incident managers.', 'pinned' => true,
            'version' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $internalId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/timeline', [
            'entry_type' => IncidentTimelineEntryType::Observation->value,
            'visibility' => IncidentTimelineVisibility::Internal->value,
            'occurred_at' => $occurredAt->toIso8601String(), 'summary' => 'Internal responder hypothesis.',
            'details' => 'Unverified technical analysis restricted to incident managers.', 'pinned' => true,
        ])->assertCreated()->json('data.id');
        $auditorId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/timeline', [
            'entry_type' => IncidentTimelineEntryType::Action->value,
            'visibility' => IncidentTimelineVisibility::Auditor->value,
            'occurred_at' => now()->subMinute()->startOfSecond()->toIso8601String(),
            'summary' => 'The affected subnet was isolated.', 'details' => 'Approved containment action recorded for external assurance.',
        ])->assertCreated()->json('data.id');
        $internal = IncidentTimelineEntry::query()->findOrFail($internalId);
        $auditor = IncidentTimelineEntry::query()->findOrFail($auditorId);
        $payload = [
            'incident_id' => $auditor->incident_id, 'version' => $auditor->version,
            'entry_type' => $auditor->entry_type->value, 'visibility' => $auditor->visibility->value,
            'occurred_at' => $auditor->occurred_at->toIso8601String(), 'summary' => $auditor->summary,
            'details' => $auditor->details, 'pinned' => $auditor->pinned,
            'incident_snapshot' => $auditor->incident_snapshot, 'recorded_by' => $auditor->recorded_by,
            'recorded_at' => $auditor->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $auditor->fingerprint);

        $readerResponse = $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id.'/timeline')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $auditor->id);
        $this->assertStringNotContainsString('Internal responder hypothesis', $readerResponse->getContent());
        $this->actingAs($manager)->getJson('/api/incidents/'.$incident->id.'/timeline')->assertOk()->assertJsonCount(2, 'data');
        Livewire::actingAs($reader);
        Livewire::test(TimelineEntriesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$auditor])->assertCanNotSeeTableRecords([$internal])->assertTableActionHidden('record');
        $auditor->load('recorder:id,name');
        $rendered = view('filament.incident-timeline-entry', ['record' => $auditor])->render();
        $this->assertStringContainsString('The affected subnet was isolated.', $rendered);
        $this->assertStringContainsString($auditor->fingerprint, $rendered);

        try {
            $auditor->delete();
            $this->fail('Expected timeline evidence to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $migration = require database_path('migrations/2026_08_24_630000_create_incident_timeline_entries.php');
        $migration->down();
        $this->assertDatabaseHas('incident_timeline_entries', ['id' => $auditor->id, 'fingerprint' => $auditor->fingerprint]);
    }

    public function test_timeline_history_bound_is_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Bounded incident timeline', 'severity' => 'Medium',
        ]);
        $now = now()->startOfSecond();
        collect(range(1, 499))->map(fn (int $version): array => [
            'incident_id' => $incident->id, 'version' => $version, 'entry_type' => IncidentTimelineEntryType::Observation->value,
            'visibility' => IncidentTimelineVisibility::Auditor->value, 'occurred_at' => $now,
            'summary' => 'Existing bounded entry '.$version, 'details' => null, 'pinned' => false,
            'incident_snapshot' => '{}', 'recorded_by' => $manager->id, 'recorded_at' => $now,
            'fingerprint' => str_pad((string) $version, 64, '0', STR_PAD_LEFT), 'created_at' => $now, 'updated_at' => $now,
        ])->chunk(40)->each(fn ($rows) => DB::table('incident_timeline_entries')->insert($rows->all()));
        $service = app(IncidentTimelineManager::class);
        $service->record($manager, $incident, [
            'entry_type' => IncidentTimelineEntryType::Action->value, 'visibility' => IncidentTimelineVisibility::Auditor->value,
            'occurred_at' => $now, 'summary' => 'Entry 500 succeeds.',
        ]);
        $this->assertSame(500, $incident->timelineEntries()->count());
        try {
            $service->record($manager, $incident, [
                'entry_type' => IncidentTimelineEntryType::Action->value, 'visibility' => IncidentTimelineVisibility::Auditor->value,
                'occurred_at' => $now, 'summary' => 'Entry 501 fails.',
            ]);
            $this->fail('Expected the timeline bound to reject entry 501.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertSame(500, $incident->timelineEntries()->count());
    }

    public function test_manager_generates_versioned_verified_final_incident_report_with_exact_acl(): void
    {
        Storage::fake('private');
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $reader->givePermissionTo('Manage Incident Evidence');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Governed final report incident', 'severity' => 'Critical', 'involves_data' => true,
        ]);
        $evidence = $this->acceptedEvidence($manager, 'incidents/final-report.txt', 'retained report evidence');
        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/final-reports', [
            'executive_summary' => 'Too early.', 'conclusions' => 'Not final.',
        ])->assertUnprocessable()->assertJsonValidationErrors('incident');
        app(IncidentDesk::class)->advancePhase($manager, $incident, IncidentPhase::Containment, 'Containment approved.', [$evidence->id]);
        foreach ([IncidentPhase::Eradication, IncidentPhase::Recovery, IncidentPhase::LessonsLearned] as $phase) {
            app(IncidentDesk::class)->advancePhase($manager, $incident->refresh(), $phase, 'Advance to '.$phase->value.'.');
        }
        $notification = app(IncidentNotificationManager::class)->register($manager, $incident, [
            'audience' => IncidentNotificationAudience::Other->value, 'recipient' => 'Executive response team',
            'rationale' => 'Retain the notification decision chain in the report.',
        ]);
        $lessonOwner = User::factory()->create();
        $lesson = app(IncidentLessonManager::class)->register($manager, $incident, [
            'area' => IncidentLessonArea::Process->value, 'observation' => 'Escalation ownership was delayed.',
            'recommendation' => 'Exercise the escalation matrix.', 'owner_id' => $lessonOwner->id,
            'rationale' => 'Retain the lesson decision chain in the report.',
        ]);
        $asset = Asset::factory()->create(['asset_tag' => 'FINAL-01', 'name' => 'Final report server']);
        app(IncidentAffectedEntityManager::class)->link($manager, $incident, [
            'entity_type' => IncidentAffectedEntityType::Asset->value, 'entity_id' => $asset->id,
            'impact_summary' => 'The server was isolated.',
        ]);
        app(IncidentTimelineManager::class)->record($manager, $incident, [
            'entry_type' => IncidentTimelineEntryType::Observation->value, 'visibility' => IncidentTimelineVisibility::Internal->value,
            'occurred_at' => now()->subMinutes(2), 'summary' => 'Internal-only hypothesis must not enter the report.',
        ]);
        app(IncidentTimelineManager::class)->record($manager, $incident, [
            'entry_type' => IncidentTimelineEntryType::Action->value, 'visibility' => IncidentTimelineVisibility::Auditor->value,
            'occurred_at' => now()->subMinute(), 'summary' => 'Auditor-visible containment milestone.',
        ]);

        try {
            app(IncidentFinalReportManager::class)->generate($reader, $incident, [
                'executive_summary' => 'Unauthorized.', 'conclusions' => 'Unauthorized.',
            ]);
            $this->fail('Expected direct final-report service authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $response = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/final-reports', [
            'executive_summary' => 'The response contained the incident and preserved decision evidence.',
            'conclusions' => 'The point-in-time report records operator conclusions without effectiveness inference.',
            'version' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $reportId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/final-reports', [
            'executive_summary' => 'The response contained the incident and preserved decision evidence.',
            'conclusions' => 'The point-in-time report records operator conclusions without effectiveness inference.',
        ])->assertCreated()->assertJsonMissingPath('data.report_snapshot')->assertJsonMissingPath('data.report_path')->json('data.id');
        $report = IncidentFinalReport::query()->findOrFail($reportId);
        $this->assertSame(1, $report->version);
        $this->assertSame('Final report server', data_get($report->report_snapshot, 'affected_entities.0.entity_snapshot.name'));
        $this->assertSame('Auditor-visible containment milestone.', data_get($report->report_snapshot, 'auditor_timeline.0.summary'));
        $this->assertStringNotContainsString('Internal-only hypothesis', json_encode($report->report_snapshot, JSON_THROW_ON_ERROR));
        $this->assertSame($evidence->id, data_get($report->report_snapshot, 'evidence_manifest.0.file_attachment_id'));
        $this->assertSame($notification->events()->latest('version')->value('fingerprint'), data_get($report->report_snapshot, 'notifications.0.latest_event_fingerprint'));
        $this->assertSame($lesson->events()->latest('version')->value('fingerprint'), data_get($report->report_snapshot, 'lessons.0.latest_event_fingerprint'));
        $this->assertSame(data_get($report->report_snapshot, 'notifications.0.latest_event_fingerprint'), data_get($report->report_snapshot, 'source_fingerprints.notification_latest_events.0'));
        $this->assertSame(data_get($report->report_snapshot, 'lessons.0.latest_event_fingerprint'), data_get($report->report_snapshot, 'source_fingerprints.lesson_latest_events.0'));
        Storage::disk('private')->assertExists($report->report_path);
        $payload = [
            'incident_id' => $report->incident_id, 'version' => $report->version, 'report_snapshot' => $report->report_snapshot,
            'evidence_attachment_ids' => $report->evidence_attachment_ids, 'generated_by' => $report->generated_by,
            'generated_at' => $report->generated_at->toIso8601String(), 'report_disk' => $report->report_disk,
            'report_path' => $report->report_path, 'report_size' => $report->report_size, 'report_sha256' => $report->report_sha256,
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $report->fingerprint);
        $download = $this->actingAs($manager)->get(route('incident-final-reports.download', $report))->assertOk();
        $this->assertSame($report->report_sha256, hash('sha256', $download->streamedContent()));
        $this->actingAs($reader)->get(route('incident-final-reports.download', $report))->assertForbidden();
        $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id.'/final-reports')
            ->assertOk()->assertJsonMissingPath('data.0.report_snapshot')->assertJsonMissingPath('data.0.report_path');

        Livewire::actingAs($manager);
        Livewire::test(FinalReportsRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$report])->assertTableActionVisible('inspect', $report);
        $report->load('generator:id,name');
        $rendered = view('filament.incident-final-report', ['record' => $report])->render();
        $this->assertStringContainsString('The response contained the incident', $rendered);
        $this->assertStringContainsString($report->report_sha256, $rendered);

        Storage::disk('private')->put($report->report_path, 'tampered report');
        $this->actingAs($manager)->get(route('incident-final-reports.download', $report))->assertStatus(409);
        try {
            $report->delete();
            $this->fail('Expected final-report evidence to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $migration = require database_path('migrations/2026_08_24_640000_create_incident_final_reports.php');
        $migration->down();
        $this->assertDatabaseHas('incident_final_reports', ['id' => $report->id, 'fingerprint' => $report->fingerprint]);
    }

    public function test_final_report_version_bound_is_exact(): void
    {
        Storage::fake('private');
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Bounded final reporting', 'severity' => 'Medium',
        ]);
        foreach ([IncidentPhase::Containment, IncidentPhase::Eradication, IncidentPhase::Recovery, IncidentPhase::LessonsLearned] as $phase) {
            app(IncidentDesk::class)->advancePhase($manager, $incident->refresh(), $phase, 'Advance.');
        }
        foreach (range(1, 19) as $version) {
            IncidentFinalReport::factory()->create([
                'incident_id' => $incident->id, 'generated_by' => $manager->id, 'version' => $version,
                'report_path' => 'incident-final-reports/existing-'.$version.'.pdf',
            ]);
        }
        $service = app(IncidentFinalReportManager::class);
        $twentieth = $service->generate($manager, $incident, ['executive_summary' => 'Version twenty.', 'conclusions' => 'Bounded.']);
        $this->assertSame(20, $twentieth->version);
        try {
            $service->generate($manager, $incident, ['executive_summary' => 'Version twenty one.', 'conclusions' => 'Rejected.']);
            $this->fail('Expected final-report version 21 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertSame(20, $incident->finalReports()->count());
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
            'evidence_manifest' => $transition->evidence_manifest,
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

    public function test_manager_records_governed_notification_determination_and_delivery_history(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Customer data exposure', 'severity' => 'Critical', 'involves_data' => true,
            'involves_pii' => true, 'is_breach' => true,
        ]);
        $incident->update(['root_cause' => 'Compromised service credential', 'business_impact' => 'Customer records exposed']);
        $deadline = now()->addHours(72)->startOfSecond();

        $response = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/notifications', [
            'audience' => IncidentNotificationAudience::Regulator->value,
            'framework' => 'GDPR Article 33', 'recipient' => 'Lead supervisory authority',
            'deadline_at' => $deadline->toIso8601String(), 'rationale' => 'Assess the deliberate regulatory notification decision.',
            'status' => IncidentNotificationStatus::Sent->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertNull($response->json('data'));

        $notificationId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/notifications', [
            'audience' => IncidentNotificationAudience::Regulator->value,
            'framework' => 'GDPR Article 33', 'recipient' => 'Lead supervisory authority',
            'deadline_at' => $deadline->toIso8601String(), 'rationale' => 'Assess the deliberate regulatory notification decision.',
        ])->assertCreated()->assertJsonPath('data.status', IncidentNotificationStatus::AssessmentPending->value)
            ->json('data.id');
        $notification = IncidentNotification::query()->findOrFail($notificationId);
        $this->assertSame(1, $notification->events()->count());

        $this->actingAs($reader)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'status' => IncidentNotificationStatus::Required->value, 'rationale' => 'Unauthorized decision.',
        ])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'status' => IncidentNotificationStatus::Required->value,
            'rationale' => 'The incident facts meet the deliberately selected notification framework.',
        ])->assertOk()->assertJsonPath('notification.status', IncidentNotificationStatus::Required->value);
        $this->assertSame('pending', $notification->fresh()->deadline_status);
        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'deadline_at' => null, 'rationale' => 'Required records cannot remove their deadline.',
        ])->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'status' => IncidentNotificationStatus::Prepared->value,
            'delivery_reference' => 'DRAFT-REFERENCE',
            'rationale' => 'Notification content was prepared outside Fynix.',
        ])->assertOk();
        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'status' => IncidentNotificationStatus::Sent->value,
            'delivery_reference' => null,
            'rationale' => 'A sent decision cannot omit its external reference.',
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_reference');
        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'status' => IncidentNotificationStatus::Sent->value,
            'delivery_reference' => 'REG-ACK-2026-0042',
            'rationale' => 'Operator recorded external submission and acknowledgement reference.',
        ])->assertOk()->assertJsonPath('notification.status', IncidentNotificationStatus::Sent->value);

        $notification->refresh();
        $this->assertNotNull($notification->sent_at);
        $this->assertSame('not_applicable', $notification->deadline_status);
        $this->assertSame(4, $notification->events()->count());
        $event = $notification->events()->reorder()->latest('version')->firstOrFail();
        $payload = [
            'incident_id' => $event->incident_id, 'incident_notification_id' => $event->incident_notification_id,
            'version' => $event->version, 'event_type' => $event->event_type,
            'before_snapshot' => $event->before_snapshot, 'after_snapshot' => $event->after_snapshot,
            'rationale' => $event->rationale, 'recorded_by' => $event->recorded_by,
            'recorded_at' => $event->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $event->fingerprint);
        $this->assertSame('REG-ACK-2026-0042', data_get($event->after_snapshot, 'delivery_reference'));
        $this->assertSame('Compromised service credential', data_get($event->after_snapshot, 'incident.root_cause'));
        $this->assertSame('Customer records exposed', data_get($event->after_snapshot, 'incident.business_impact'));
        $this->assertNotEmpty(data_get($event->after_snapshot, 'incident.playbook_snapshot'));

        $this->actingAs($manager)->postJson('/api/incident-notifications/'.$notification->id.'/decisions', [
            'recipient' => 'Changed after delivery', 'rationale' => 'Terminal mutation.',
        ])->assertUnprocessable();
        $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id.'/notifications')
            ->assertOk()->assertJsonPath('data.0.events_count', 4);
        $this->actingAs($reader)->getJson('/api/incident-notifications/'.$notification->id.'/events?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 4);
        Livewire::actingAs($reader);
        Livewire::test(NotificationsRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$notification])
            ->assertTableActionHidden('record_decision', $notification)
            ->assertTableActionVisible('inspect_history', $notification);
        $notification->load('events.actor:id,name');
        $renderedHistory = view('filament.incident-notification-history', ['notification' => $notification])->render();
        $this->assertStringContainsString('REG-ACK-2026-0042', $renderedHistory);
        $this->assertStringContainsString($event->fingerprint, $renderedHistory);

        try {
            $event->update(['rationale' => 'Rewrite']);
            $this->fail('Expected notification decision history to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $migration = require database_path('migrations/2026_08_24_590000_create_governed_incident_notifications.php');
        $migration->down();
        $this->assertDatabaseHas('incident_notification_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);
    }

    public function test_notification_service_reauthorizes_and_rejects_legacy_incidents(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Regular User');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Governed notification boundary', 'severity' => 'High',
        ]);
        $data = [
            'audience' => IncidentNotificationAudience::Partner->value, 'recipient' => 'Processor contact',
            'rationale' => 'Assess partner notification.',
        ];

        try {
            app(IncidentNotificationManager::class)->register($outsider, $incident, $data);
            $this->fail('Expected direct service authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $legacy = Incident::query()->create([
            'number' => 'INC-2025-0099', 'title' => 'Legacy notification boundary',
            'severity' => 'High', 'status' => 'Open', 'phase' => IncidentPhase::Identification,
            'lead_id' => $manager->id, 'reporter_id' => $manager->id, 'detected_at' => now()->subYear(),
        ]);
        try {
            app(IncidentNotificationManager::class)->register($manager, $legacy, $data);
            $this->fail('Expected legacy incident notification governance to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertDatabaseCount('incident_notifications', 0);
        $this->assertDatabaseCount('incident_notification_events', 0);
    }

    public function test_notification_record_and_event_history_bounds_are_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Bounded notification register', 'severity' => 'High',
        ]);
        $service = app(IncidentNotificationManager::class);
        $first = null;
        for ($index = 1; $index <= 100; $index++) {
            $record = $service->register($manager, $incident, [
                'audience' => IncidentNotificationAudience::Other->value,
                'recipient' => 'Stakeholder '.$index, 'rationale' => 'Register bounded stakeholder '.$index.'.',
            ]);
            $first ??= $record;
        }
        $this->assertSame(100, $incident->notifications()->count());
        try {
            $service->register($manager, $incident, [
                'audience' => IncidentNotificationAudience::Other->value,
                'recipient' => 'Stakeholder 101', 'rationale' => 'One too many.',
            ]);
            $this->fail('Expected notification record bound to reject the 101st record.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertSame(100, $incident->notifications()->count());

        for ($version = 2; $version <= 50; $version++) {
            $service->recordDecision($manager, $first, [
                'framework' => 'Deliberate framework version '.$version,
                'rationale' => 'Update the governed assessment context.',
            ]);
        }
        $this->assertSame(50, $first->events()->count());
        try {
            $service->recordDecision($manager, $first, [
                'framework' => 'Event 51', 'rationale' => 'One too many.',
            ]);
            $this->fail('Expected notification event bound to reject the 51st event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notification', $exception->errors());
        }
        $this->assertSame(50, $first->events()->count());
    }

    public function test_lessons_learned_are_attributable_owner_scoped_and_forward_only(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $reader->assignRole('Regular User');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Lessons governance', 'severity' => 'High',
        ]);
        $payload = [
            'area' => IncidentLessonArea::Process->value,
            'observation' => 'Escalation ownership was unclear during containment.',
            'recommendation' => 'Publish and exercise a named escalation matrix.',
            'owner_id' => $owner->id, 'target_date' => now()->addMonth()->toDateString(),
            'rationale' => 'Capture the post-incident review conclusion.',
        ];
        $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/lessons', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('incident');

        foreach ([IncidentPhase::Containment, IncidentPhase::Eradication, IncidentPhase::Recovery, IncidentPhase::LessonsLearned] as $phase) {
            app(IncidentDesk::class)->advancePhase($manager, $incident, $phase, 'Advance to '.$phase->value.'.');
            $incident->refresh();
        }
        $invalid = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/lessons', $payload + [
            'status' => IncidentLessonStatus::Implemented->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertNull($invalid->json('data'));
        $lessonId = $this->actingAs($manager)->postJson('/api/incidents/'.$incident->id.'/lessons', $payload)
            ->assertCreated()->assertJsonPath('data.status', IncidentLessonStatus::Proposed->value)->json('data.id');
        $lesson = IncidentLesson::query()->findOrFail($lessonId);
        $this->assertSame('pending', $lesson->target_status);

        try {
            app(IncidentLessonManager::class)->recordProgress($reader, $lesson, [
                'status' => IncidentLessonStatus::InProgress->value, 'rationale' => 'Direct unauthorized update.',
            ]);
            $this->fail('Expected direct lesson service authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($owner)->postJson('/api/incident-lessons/'.$lesson->id.'/progress', [
            'recommendation' => 'Owner rewrite', 'rationale' => 'Unauthorized scope.',
        ])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/incident-lessons/'.$lesson->id.'/progress', [
            'status' => IncidentLessonStatus::Implemented->value, 'rationale' => 'Cannot skip work.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($owner)->postJson('/api/incident-lessons/'.$lesson->id.'/progress', [
            'status' => IncidentLessonStatus::InProgress->value, 'rationale' => 'Owner began the improvement.',
        ])->assertOk()->assertJsonPath('lesson.status', IncidentLessonStatus::InProgress->value)
            ->assertJsonMissingPath('data.after_snapshot.incident');
        $this->actingAs($manager)->postJson('/api/incident-lessons/'.$lesson->id.'/progress', [
            'status' => IncidentLessonStatus::Implemented->value, 'rationale' => 'Manager recorded implementation.',
        ])->assertOk()->assertJsonPath('lesson.status', IncidentLessonStatus::Implemented->value);

        $lesson->refresh();
        $this->assertSame('not_applicable', $lesson->target_status);
        $this->assertSame(3, $lesson->events()->count());
        $event = $lesson->events()->reorder()->latest('version')->firstOrFail();
        $fingerprintPayload = [
            'incident_id' => $event->incident_id, 'incident_lesson_id' => $event->incident_lesson_id,
            'version' => $event->version, 'event_type' => $event->event_type,
            'before_snapshot' => $event->before_snapshot, 'after_snapshot' => $event->after_snapshot,
            'rationale' => $event->rationale, 'recorded_by' => $event->recorded_by,
            'recorded_at' => $event->recorded_at->toIso8601String(),
        ];
        $this->assertSame(hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $event->fingerprint);
        $this->assertSame($owner->email, data_get($event->after_snapshot, 'owner.email'));
        $this->assertSame(IncidentPhase::LessonsLearned->value, data_get($event->after_snapshot, 'incident.phase'));
        $this->actingAs($owner)->postJson('/api/incident-lessons/'.$lesson->id.'/progress', [
            'status' => IncidentLessonStatus::ClosedWithoutAction->value, 'rationale' => 'Rewrite terminal state.',
        ])->assertUnprocessable();
        $this->actingAs($reader)->getJson('/api/incidents/'.$incident->id.'/lessons')
            ->assertOk()->assertJsonPath('data.0.events_count', 3);
        $this->actingAs($owner)->getJson('/api/incident-lessons/'.$lesson->id.'/events?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 3)
            ->assertJsonMissingPath('data.0.after_snapshot.incident')
            ->assertJsonMissingPath('data.1.after_snapshot.incident');
        $this->actingAs($reader)->getJson('/api/incident-lessons/'.$lesson->id.'/events?per_page=1')
            ->assertOk()->assertJsonPath('data.0.after_snapshot.incident.number', $incident->number);
        Livewire::actingAs($reader);
        Livewire::test(LessonsRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->assertCanSeeTableRecords([$lesson])
            ->assertTableActionHidden('record_progress', $lesson)
            ->assertTableActionVisible('inspect_history', $lesson);
        $lesson->load('events.actor:id,name');
        $renderedHistory = view('filament.incident-lesson-history', ['lesson' => $lesson])->render();
        $this->assertStringContainsString('Escalation ownership was unclear', $renderedHistory);
        $this->assertStringContainsString($event->fingerprint, $renderedHistory);

        try {
            $event->update(['rationale' => 'Rewrite']);
            $this->fail('Expected lesson history to remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $migration = require database_path('migrations/2026_08_24_600000_create_governed_incident_lessons.php');
        $migration->down();
        $this->assertDatabaseHas('incident_lesson_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);
    }

    public function test_lesson_record_and_event_history_bounds_are_exact(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $incident = app(IncidentDesk::class)->createFromPlaybook($manager, IncidentPlaybook::factory()->create(), [
            'title' => 'Bounded lesson register', 'severity' => 'Medium',
        ]);
        foreach ([IncidentPhase::Containment, IncidentPhase::Eradication, IncidentPhase::Recovery, IncidentPhase::LessonsLearned] as $phase) {
            app(IncidentDesk::class)->advancePhase($manager, $incident, $phase, 'Advance.');
            $incident->refresh();
        }
        $service = app(IncidentLessonManager::class);
        $first = null;
        for ($index = 1; $index <= 100; $index++) {
            $record = $service->register($manager, $incident, [
                'area' => IncidentLessonArea::Other->value, 'observation' => 'Observation '.$index,
                'recommendation' => 'Recommendation '.$index, 'owner_id' => $manager->id,
                'rationale' => 'Register lesson '.$index.'.',
            ]);
            $first ??= $record;
        }
        $this->assertSame(100, $incident->lessons()->count());
        try {
            $service->register($manager, $incident, [
                'area' => IncidentLessonArea::Other->value, 'observation' => 'Observation 101',
                'recommendation' => 'Recommendation 101', 'owner_id' => $manager->id, 'rationale' => 'One too many.',
            ]);
            $this->fail('Expected lesson record bound to reject the 101st record.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $this->assertSame(100, $incident->lessons()->count());

        for ($version = 2; $version <= 50; $version++) {
            $service->recordProgress($manager, $first, [
                'recommendation' => 'Recommendation version '.$version,
                'rationale' => 'Refine the lesson recommendation.',
            ]);
        }
        $this->assertSame(50, $first->events()->count());
        try {
            $service->recordProgress($manager, $first, [
                'recommendation' => 'Event 51', 'rationale' => 'One too many.',
            ]);
            $this->fail('Expected lesson event bound to reject the 51st event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson', $exception->errors());
        }
        $this->assertSame(50, $first->events()->count());
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
