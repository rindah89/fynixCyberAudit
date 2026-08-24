<?php

namespace Tests\Feature;

use App\Enums\IncidentPhase;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\PhaseTransitionsRelationManager;
use App\Incidents\IncidentDesk;
use App\Models\Incident;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPlaybook;
use App\Models\IncidentPlaybookTask;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
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
}
