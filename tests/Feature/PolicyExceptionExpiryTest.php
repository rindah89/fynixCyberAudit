<?php

namespace Tests\Feature;

use App\Enums\PolicyExceptionMonitoringState;
use App\Enums\PolicyExceptionStatus;
use App\Filament\Exports\PolicyExceptionExporter;
use App\Models\Policy;
use App\Models\PolicyExceptionExpiration;
use App\Models\User;
use App\PolicyCompliance\PolicyExceptionExpiryManager;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use App\PolicyCompliance\PolicyExceptionMonitoringManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyExceptionExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(10));
    }

    public function test_scheduler_expires_governed_exception_once_with_immutable_evidence(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $approver->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $manager = app(PolicyExceptionGovernanceManager::class);
        $expirationDate = today()->addDay();
        $exception = $manager->submit($policy, $requester, [
            'name' => 'Temporary privileged-access exception',
            'description' => 'A bounded deviation.',
            'justification' => 'A legacy dependency remains.',
            'risk_assessment' => 'Elevated access exposure.',
            'compensating_controls' => 'Daily privileged-account reconciliation.',
            'effective_date' => today()->toDateString(),
            'expiration_date' => $expirationDate->toDateString(),
            'review_frequency_days' => 30,
        ]);
        $manager->decide($exception, $approver, [
            'decision' => 'approved',
            'decision_summary' => 'Approved until the bounded expiry date.',
        ]);

        $this->travelTo($expirationDate->copy()->addDay()->addMinutes(10));
        $this->artisan('fynix:reconcile-policy-exceptions')
            ->expectsOutput('Expired 1 governed policy exception(s).')
            ->assertSuccessful();

        $exception->refresh();
        $this->assertSame(PolicyExceptionStatus::Expired, $exception->status);
        $this->assertFalse($exception->isActive());
        $expiration = PolicyExceptionExpiration::query()->firstOrFail();
        $payload = [
            'policy_exception_id' => $expiration->policy_exception_id,
            'prior_status' => $expiration->prior_status->value,
            'expiration_date' => $expiration->expiration_date->toDateString(),
            'expired_at' => $expiration->expired_at->toISOString(),
            'reconciled_at' => $expiration->reconciled_at->toISOString(),
            'reconciliation_id' => $expiration->reconciliation_id,
            'source' => $expiration->source,
            'exception_snapshot' => $expiration->exception_snapshot,
        ];
        $this->assertSame(
            hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $expiration->fingerprint,
        );

        $this->artisan('fynix:reconcile-policy-exceptions')
            ->expectsOutput('Expired 0 governed policy exception(s).')
            ->assertSuccessful();
        $this->assertDatabaseCount('policy_exception_expirations', 1);
    }

    public function test_exception_remains_valid_through_expiration_day_and_open_issue_keeps_action_priority(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $monitor = User::factory()->create();
        $approver->givePermissionTo('Update Policies');
        $monitor->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $governance = app(PolicyExceptionGovernanceManager::class);
        $expirationDate = today()->addDay();
        $exception = $governance->submit($policy, $requester, [
            'name' => 'Temporary network exception',
            'description' => 'A bounded deviation.',
            'justification' => 'A legacy dependency remains.',
            'risk_assessment' => 'Elevated network exposure.',
            'compensating_controls' => 'Daily firewall-log review.',
            'effective_date' => today()->toDateString(),
            'expiration_date' => $expirationDate->toDateString(),
            'review_frequency_days' => 30,
        ]);
        $governance->decide($exception, $approver, [
            'decision' => 'approved', 'decision_summary' => 'Approved with monitoring.',
        ]);
        $review = app(PolicyExceptionMonitoringManager::class)->review($exception, $monitor, [
            'outcome' => 'needs_action',
            'review_summary' => 'The compensating control needs corrective action.',
            'control_effectiveness' => 'One daily review was missed.',
        ]);

        $this->travelTo($expirationDate->copy()->endOfDay());
        $this->artisan('fynix:reconcile-policy-exceptions')
            ->expectsOutput('Expired 0 governed policy exception(s).')->assertSuccessful();
        $this->assertSame(PolicyExceptionStatus::Approved, $exception->fresh()->status);
        $this->assertTrue($exception->fresh()->isActive());

        $this->travelTo($expirationDate->copy()->addDay());
        $this->artisan('fynix:reconcile-policy-exceptions')
            ->expectsOutput('Expired 1 governed policy exception(s).')->assertSuccessful();
        $exception->refresh();
        $this->assertSame(PolicyExceptionStatus::Expired, $exception->status);
        $this->assertSame(PolicyExceptionMonitoringState::ActionRequired, $exception->monitoring_status);
        $this->assertSame($review->fingerprint, $exception->expiration->exception_snapshot['latest_monitoring_review']['fingerprint']);
        $this->assertArrayNotHasKey('evidence_manifest', $exception->expiration->exception_snapshot['latest_monitoring_review']);
    }

    public function test_authorized_history_operator_export_and_rollback_retain_expiration_evidence(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $approver->givePermissionTo(['Update Policies', 'Read Policies']);
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $manager = app(PolicyExceptionGovernanceManager::class);
        $exception = $manager->submit($policy, $requester, [
            'name' => 'Temporary processing exception',
            'description' => 'A bounded deviation.',
            'justification' => 'A legacy dependency remains.',
            'risk_assessment' => 'Elevated processing exposure.',
            'compensating_controls' => 'Daily reconciliation.',
            'effective_date' => today()->toDateString(),
            'expiration_date' => today()->addDay()->toDateString(),
            'review_frequency_days' => 30,
        ]);
        $manager->decide($exception, $approver, [
            'decision' => 'approved', 'decision_summary' => 'Approved through the stated date.',
        ]);
        $this->travelTo(today()->addDays(2)->addMinutes(10));
        app(PolicyExceptionExpiryManager::class)->reconcile();
        $expiration = $exception->fresh()->expiration;

        Sanctum::actingAs($requester);
        $this->getJson("/api/policies/{$policy->id}/exception-requests")
            ->assertOk()
            ->assertJsonPath('data.0.status', PolicyExceptionStatus::Expired->value)
            ->assertJsonPath('data.0.expiration.fingerprint', $expiration->fingerprint)
            ->assertJsonPath('data.0.expiration.source', 'server_reconciliation');
        $this->view('filament.policy-exception-governance', [
            'exception' => $exception->fresh()->load(['requester', 'decisions.decider', 'monitoringReviews', 'expiration']),
        ])->assertSee('Server expiry reconciliation')
            ->assertSee($expiration->fingerprint)
            ->assertSee($expiration->reconciliation_id);
        $this->assertContains('expiration_evidence', collect(PolicyExceptionExporter::getColumns())->map->getName());

        try {
            $expiration->update(['source' => 'rewritten']);
            $this->fail('Expiration evidence was mutable.');
        } catch (\LogicException) {
            $this->assertSame('server_reconciliation', $expiration->fresh()->source);
        }
        try {
            $expiration->delete();
            $this->fail('Expiration evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_exception_expirations', ['id' => $expiration->id]);
        }

        $migration = require database_path('migrations/2026_08_24_510000_create_policy_exception_expirations.php');
        $migration->down();
        $this->assertDatabaseHas('policy_exception_expirations', [
            'id' => $expiration->id,
            'fingerprint' => $expiration->fingerprint,
        ]);
    }
}
