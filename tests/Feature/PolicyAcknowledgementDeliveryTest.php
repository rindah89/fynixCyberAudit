<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Models\Policy;
use App\Models\PolicyAcknowledgementDelivery;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyAcknowledgementDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_launch_delivers_one_attributable_database_notification_per_assignment(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $employees = User::factory()->count(2)->create();
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id,
            'effective_date' => today()->subDay(),
            'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);

        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Annual policy acknowledgement',
            'instructions' => 'Read the assigned snapshot before acknowledging.',
            'due_at' => now()->addWeek(),
            'audience_user_ids' => $employees->pluck('id')->all(),
        ]);

        $this->assertDatabaseCount('policy_acknowledgement_deliveries', 2);
        $this->assertDatabaseCount('notifications', 2);
        foreach ($campaign->assignments()->with(['user', 'delivery'])->get() as $assignment) {
            $delivery = $assignment->delivery;
            $this->assertInstanceOf(PolicyAcknowledgementDelivery::class, $delivery);
            $this->assertSame($assignment->user_id, $delivery->recipient_snapshot['id']);
            $this->assertSame($assignment->user->email, $delivery->recipient_snapshot['email']);
            $this->assertSame($campaign->policy_fingerprint, $delivery->campaign_snapshot['policy_fingerprint']);
            $this->assertDatabaseHas('notifications', [
                'id' => $delivery->notification_id,
                'notifiable_type' => User::class,
                'notifiable_id' => $assignment->user_id,
            ]);
            $payload = [
                'policy_acknowledgement_assignment_id' => $assignment->id,
                'policy_acknowledgement_campaign_id' => $campaign->id,
                'user_id' => $assignment->user_id,
                'channel' => 'database',
                'notification_id' => $delivery->notification_id,
                'recipient_snapshot' => $delivery->recipient_snapshot,
                'campaign_snapshot' => $delivery->campaign_snapshot,
                'attempted_at' => $delivery->attempted_at->toISOString(),
                'delivered_at' => $delivery->delivered_at->toISOString(),
            ];
            $this->assertSame(
                hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $delivery->fingerprint,
            );
        }

        $assignment = $campaign->assignments()->with(['user', 'campaign', 'delivery', 'acknowledgement'])->firstOrFail();
        Sanctum::actingAs($manager);
        $this->getJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/report")
            ->assertOk()->assertJsonFragment(['fingerprint' => $assignment->delivery->fingerprint]);
        Sanctum::actingAs($assignment->user);
        $this->getJson('/api/policy-acknowledgements/mine')
            ->assertOk()->assertJsonPath('data.0.delivery.notification_id', $assignment->delivery->notification_id);
        $this->view('filament.policy-acknowledgement', ['assignment' => $assignment])
            ->assertSee('In-app notification delivered')
            ->assertSee($assignment->delivery->notification_id)
            ->assertSee($assignment->delivery->fingerprint);
        $columns = collect(PolicyAcknowledgementAssignmentExporter::getColumns())->map->getName();
        $this->assertContains('delivery.fingerprint', $columns);
        $this->assertContains('delivery.campaign_snapshot', $columns);
    }

    public function test_cancelled_delivery_rolls_back_campaign_and_successful_evidence_is_immutable_and_retained(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $payload = [
            'title' => 'Policy acknowledgement', 'due_at' => now()->addWeek(),
            'audience_user_ids' => [$employee->id],
        ];

        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            app(PolicyAcknowledgementManager::class)->launch($policy, $manager, $payload);
            $this->fail('A cancelled database delivery was represented as delivered.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertDatabaseCount('policy_acknowledgement_campaigns', 0);
        $this->assertDatabaseCount('policy_acknowledgement_assignments', 0);
        $this->assertDatabaseCount('policy_acknowledgement_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);

        Event::forget(NotificationSending::class);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, $payload);
        $delivery = $campaign->assignments->first()->delivery;
        DB::table('notifications')->where('id', $delivery->notification_id)->delete();
        $this->assertDatabaseHas('policy_acknowledgement_deliveries', ['id' => $delivery->id]);
        try {
            $delivery->update(['channel' => 'rewritten']);
            $this->fail('Delivery evidence was mutable.');
        } catch (\LogicException) {
            $this->assertSame('database', $delivery->fresh()->channel);
        }
        try {
            $delivery->delete();
            $this->fail('Delivery evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_acknowledgement_deliveries', ['id' => $delivery->id]);
        }

        $migration = require database_path('migrations/2026_08_24_520000_create_policy_acknowledgement_deliveries.php');
        $migration->down();
        $this->assertDatabaseHas('policy_acknowledgement_deliveries', ['id' => $delivery->id, 'fingerprint' => $delivery->fingerprint]);
    }
}
