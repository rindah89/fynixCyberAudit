<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementEscalationManager;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PolicyAcknowledgementEscalationMysqlConcurrencyTest extends TestCase
{
    public function test_escalation_serializes_with_campaign_closure(): void
    {
        $this->requireIsolatedMysql();
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $owner->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Governed policy content.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $owner, [
            'title' => 'Concurrent escalation', 'due_at' => now()->addDay(), 'audience_user_ids' => [$employee->id],
        ]);
        $asOf = $campaign->due_at->copy()->addDays(4);
        $marker = tempnam(sys_get_temp_dir(), 'policy-escalation-lock-');
        unlink($marker);

        $escalation = $this->fork(function () use ($marker, $asOf): void {
            $manager = new class($marker) extends PolicyAcknowledgementEscalationManager
            {
                public function __construct(private readonly string $marker) {}

                protected function afterPolicyLock(Policy $policy): void
                {
                    file_put_contents($this->marker, 'locked');
                    usleep(750_000);
                }
            };
            $manager->reconcile($asOf);
        });
        $this->waitForMarker($marker);
        $closure = $this->fork(fn () => app(PolicyAcknowledgementManager::class)->close(
            $campaign->fresh(), User::query()->findOrFail($owner->id),
        ));

        $this->assertSame([0, 0], $this->waitFor([$escalation, $closure]));
        DB::disconnect();
        DB::reconnect();
        $this->assertDatabaseHas('policy_acknowledgement_escalations', [
            'policy_acknowledgement_campaign_id' => $campaign->id,
        ]);
        $this->assertNotNull($campaign->fresh()->closed_at);

        @unlink($marker);
        DB::table('policy_acknowledgement_escalations')->where('policy_acknowledgement_campaign_id', $campaign->id)->delete();
        DB::table('policy_acknowledgement_deliveries')->where('policy_acknowledgement_campaign_id', $campaign->id)->delete();
        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', [$owner->id, $employee->id])->delete();
        DB::table('policy_acknowledgement_assignments')->where('policy_acknowledgement_campaign_id', $campaign->id)->delete();
        DB::table('policy_acknowledgement_campaigns')->where('id', $campaign->id)->delete();
        DB::table('policies')->where('id', $policy->id)->delete();
        DB::table('model_has_permissions')->where('model_id', $owner->id)->delete();
        DB::table('users')->whereIn('id', [$owner->id, $employee->id])->delete();
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL governance database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the policy acknowledgement escalation concurrency test');
        }
    }

    private function fork(callable $operation): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::disconnect();
                DB::reconnect();
                $operation();
                exit(0);
            } catch (\Throwable) {
                exit(20);
            }
        }

        return $pid;
    }

    private function waitForMarker(string $marker): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($marker) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($marker, 'Escalation worker did not reach the policy lock boundary.');
    }

    /** @param list<int> $children */
    private function waitFor(array $children): array
    {
        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        sort($codes);

        return $codes;
    }
}
