<?php

namespace Tests\Feature;

use App\Enums\PolicyAcknowledgementReminderType;
use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Filament\Resources\PolicyAcknowledgementResource\Pages\ViewPolicyAcknowledgement;
use App\Models\Policy;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use App\PolicyCompliance\PolicyAcknowledgementReminderManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PolicyAcknowledgementReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(8));
    }

    public function test_scheduler_sends_each_due_soon_and_overdue_reminder_once_with_immutable_evidence(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Annual acknowledgement',
            'due_at' => now()->addDays(4),
            'audience_user_ids' => [$employee->id],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        $this->assertDatabaseCount('notifications', 1);

        $this->travelTo($campaign->due_at->copy()->subDays(3));
        $this->artisan('fynix:reconcile-policy-acknowledgement-reminders')
            ->expectsOutput('Delivered 1 policy acknowledgement reminder(s).')->assertSuccessful();
        $this->artisan('fynix:reconcile-policy-acknowledgement-reminders')
            ->expectsOutput('Delivered 0 policy acknowledgement reminder(s).')->assertSuccessful();

        $this->travelTo($campaign->due_at->copy());
        $this->artisan('fynix:reconcile-policy-acknowledgement-reminders')
            ->expectsOutput('Delivered 0 policy acknowledgement reminder(s).')->assertSuccessful();

        $this->travelTo($campaign->due_at->copy()->addSecond());
        $this->artisan('fynix:reconcile-policy-acknowledgement-reminders')
            ->expectsOutput('Delivered 1 policy acknowledgement reminder(s).')->assertSuccessful();
        $this->artisan('fynix:reconcile-policy-acknowledgement-reminders')
            ->expectsOutput('Delivered 0 policy acknowledgement reminder(s).')->assertSuccessful();

        $reminders = $assignment->reminders()->orderBy('id')->get();
        $this->assertSame([
            PolicyAcknowledgementReminderType::DueSoon,
            PolicyAcknowledgementReminderType::Overdue,
        ], $reminders->pluck('type')->all());
        $this->assertDatabaseCount('notifications', 3);
        foreach ($reminders as $reminder) {
            $this->assertDatabaseHas('notifications', [
                'id' => $reminder->notification_id,
                'notifiable_type' => User::class,
                'notifiable_id' => $employee->id,
            ]);
            $payload = [
                'policy_acknowledgement_assignment_id' => $assignment->id,
                'policy_acknowledgement_campaign_id' => $campaign->id,
                'user_id' => $employee->id,
                'type' => $reminder->type->value,
                'channel' => 'database',
                'notification_id' => $reminder->notification_id,
                'recipient_snapshot' => $reminder->recipient_snapshot,
                'campaign_snapshot' => $reminder->campaign_snapshot,
                'attempted_at' => $reminder->attempted_at->toISOString(),
                'delivered_at' => $reminder->delivered_at->toISOString(),
            ];
            $this->assertSame(
                hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $reminder->fingerprint,
            );
        }

        $assignment->load(['user', 'campaign', 'delivery', 'reminders', 'acknowledgement']);
        Sanctum::actingAs($manager);
        $this->getJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/report")
            ->assertOk()->assertJsonCount(2, 'data.0.reminders')
            ->assertJsonFragment(['fingerprint' => $reminders->last()->fingerprint]);
        Sanctum::actingAs($employee);
        $this->getJson('/api/policy-acknowledgements/mine')
            ->assertOk()->assertJsonCount(2, 'data.0.reminders');
        $this->view('filament.policy-acknowledgement', ['assignment' => $assignment])
            ->assertSee('Due soon in-app reminder')
            ->assertSee('Overdue in-app reminder')
            ->assertSee($reminders->last()->fingerprint);
        $this->actingAs($employee, 'web');
        Livewire::test(ViewPolicyAcknowledgement::class, ['record' => $assignment->id])
            ->assertSee($reminders->first()->fingerprint)
            ->assertSee($reminders->last()->fingerprint);
        $this->assertContains('reminder_history', collect(PolicyAcknowledgementAssignmentExporter::getColumns())->map->getName());
    }

    public function test_reconciliation_skips_ineligible_assignments_and_cancelled_delivery_rolls_back_retry_safely(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $acknowledgedUser = User::factory()->create();
        $closedUser = User::factory()->create();
        $deactivatedUser = User::factory()->create();
        $eligibleUser = User::factory()->create();
        $campaigns = collect([$acknowledgedUser, $closedUser, $deactivatedUser, $eligibleUser])
            ->map(fn (User $user, int $index) => app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
                'title' => "Reminder campaign {$index}",
                'due_at' => now()->addDays(2),
                'audience_user_ids' => [$user->id],
            ]));
        $acknowledgedAssignment = $campaigns[0]->assignments()->firstOrFail();
        app(PolicyAcknowledgementManager::class)->acknowledge($acknowledgedAssignment, $acknowledgedUser, ['acknowledged' => true]);
        app(PolicyAcknowledgementManager::class)->close($campaigns[1], $manager);
        $deactivatedUser->delete();

        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            app(PolicyAcknowledgementReminderManager::class)->reconcile();
            $this->fail('A cancelled reminder was represented as delivered.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertDatabaseCount('policy_acknowledgement_reminders', 0);

        Event::forget(NotificationSending::class);
        $this->assertSame(1, app(PolicyAcknowledgementReminderManager::class)->reconcile());
        $eligibleAssignment = $campaigns[3]->assignments()->firstOrFail();
        $reminder = $eligibleAssignment->reminders()->firstOrFail();
        $this->assertSame(PolicyAcknowledgementReminderType::DueSoon, $reminder->type);
        $this->assertDatabaseCount('policy_acknowledgement_reminders', 1);
        DB::table('notifications')->where('id', $reminder->notification_id)->delete();
        $this->assertDatabaseHas('policy_acknowledgement_reminders', ['id' => $reminder->id]);

        try {
            $reminder->update(['channel' => 'rewritten']);
            $this->fail('Reminder evidence was mutable.');
        } catch (\LogicException) {
            $this->assertSame('database', $reminder->fresh()->channel);
        }
        try {
            $reminder->delete();
            $this->fail('Reminder evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_acknowledgement_reminders', ['id' => $reminder->id]);
        }

        $migration = require database_path('migrations/2026_08_24_530000_create_policy_acknowledgement_reminders.php');
        $migration->down();
        $this->assertDatabaseHas('policy_acknowledgement_reminders', ['id' => $reminder->id, 'fingerprint' => $reminder->fingerprint]);
    }
}
