<?php

namespace Tests\Feature;

use App\Enums\IncidentPhase;
use App\Incidents\IncidentDesk;
use App\Models\IncidentPlaybook;
use App\Models\IncidentPlaybookTask;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
}
