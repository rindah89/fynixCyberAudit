<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Models\Policy;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementEscalationManager;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyAcknowledgementEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(8));
    }

    public function test_persistently_overdue_assignment_escalates_once_to_current_policy_owner(): void
    {
        $launcher = User::factory()->create();
        $launcher->givePermissionTo('Update Policies');
        $originalOwner = User::factory()->create();
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $originalOwner->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $launcher, [
            'title' => 'Annual acknowledgement', 'due_at' => now()->addDay(), 'audience_user_ids' => [$employee->id],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        $policy->update(['owner_id' => $owner->id]);

        $this->travelTo($campaign->due_at->copy()->addDays(3));
        $this->assertSame(0, app(PolicyAcknowledgementEscalationManager::class)->reconcile());
        $this->travelTo($campaign->due_at->copy()->addDays(3)->addSecond());
        $this->artisan('fynix:reconcile-policy-acknowledgement-escalations')
            ->expectsOutput('Delivered 1 policy acknowledgement escalation(s).')->assertSuccessful();
        $this->assertSame(0, app(PolicyAcknowledgementEscalationManager::class)->reconcile());

        $escalation = $assignment->escalation()->firstOrFail();
        $this->assertSame($owner->id, $escalation->escalated_to_user_id);
        $this->assertSame($employee->id, $escalation->assigned_user_id);
        $this->assertDatabaseHas('notifications', ['id' => $escalation->notification_id, 'notifiable_id' => $owner->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $escalation->notification_id, 'notifiable_id' => $originalOwner->id]);
        $payload = [
            'policy_acknowledgement_assignment_id' => $assignment->id,
            'policy_acknowledgement_campaign_id' => $campaign->id,
            'assigned_user_id' => $employee->id,
            'escalated_to_user_id' => $owner->id,
            'channel' => 'database',
            'notification_id' => $escalation->notification_id,
            'assignment_snapshot' => $escalation->assignment_snapshot,
            'recipient_snapshot' => $escalation->recipient_snapshot,
            'campaign_snapshot' => $escalation->campaign_snapshot,
            'attempted_at' => $escalation->attempted_at->toISOString(),
            'delivered_at' => $escalation->delivered_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $escalation->fingerprint);

        Sanctum::actingAs($owner);
        $this->getJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/report")
            ->assertOk()->assertJsonPath('data.0.escalation.fingerprint', $escalation->fingerprint);
        Sanctum::actingAs($employee);
        $this->getJson('/api/policy-acknowledgements/mine')
            ->assertOk()->assertJsonPath('data.0.escalation.fingerprint', $escalation->fingerprint);
        $this->assertContains('escalation_fingerprint', collect(PolicyAcknowledgementAssignmentExporter::getColumns())->map->getName());
    }

    public function test_escalation_skips_ineligible_state_and_rolls_back_cancelled_delivery(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $owner->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $owner, [
            'title' => 'Escalation campaign', 'due_at' => now()->addDay(), 'audience_user_ids' => [$employee->id],
        ]);
        $acknowledgedUser = User::factory()->create();
        $acknowledgedCampaign = app(PolicyAcknowledgementManager::class)->launch($policy, $owner, [
            'title' => 'Acknowledged campaign', 'due_at' => now()->addDay(), 'audience_user_ids' => [$acknowledgedUser->id],
        ]);
        app(PolicyAcknowledgementManager::class)->acknowledge(
            $acknowledgedCampaign->assignments()->firstOrFail(), $acknowledgedUser, ['acknowledged' => true],
        );
        $closedCampaign = app(PolicyAcknowledgementManager::class)->launch($policy, $owner, [
            'title' => 'Closed campaign', 'due_at' => now()->addDay(), 'audience_user_ids' => [User::factory()->create()->id],
        ]);
        app(PolicyAcknowledgementManager::class)->close($closedCampaign, $owner);
        $this->travelTo($campaign->due_at->copy()->addDays(4));

        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            app(PolicyAcknowledgementEscalationManager::class)->reconcile();
            $this->fail('A cancelled escalation was represented as delivered.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertDatabaseCount('policy_acknowledgement_escalations', 0);
        Event::forget(NotificationSending::class);
        $this->assertSame(1, app(PolicyAcknowledgementEscalationManager::class)->reconcile());
        $escalation = $campaign->assignments()->firstOrFail()->escalation()->firstOrFail();
        try {
            $escalation->delete();
            $this->fail('Escalation evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_acknowledgement_escalations', ['id' => $escalation->id]);
        }
        $migration = require database_path('migrations/2026_08_24_540000_create_policy_acknowledgement_escalations.php');
        $migration->down();
        $this->assertDatabaseHas('policy_acknowledgement_escalations', ['id' => $escalation->id, 'fingerprint' => $escalation->fingerprint]);
        $this->assertDatabaseMissing('policy_acknowledgement_escalations', [
            'policy_acknowledgement_campaign_id' => $acknowledgedCampaign->id,
        ]);
        $this->assertDatabaseMissing('policy_acknowledgement_escalations', [
            'policy_acknowledgement_campaign_id' => $closedCampaign->id,
        ]);
    }
}
