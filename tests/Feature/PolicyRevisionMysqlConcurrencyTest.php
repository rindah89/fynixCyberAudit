<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\PolicyRevision;
use App\Models\Risk;
use App\Models\User;
use App\PolicyCompliance\PolicyRevisionContextManager;
use App\PolicyCompliance\PolicyRevisionManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PolicyRevisionMysqlConcurrencyTest extends TestCase
{
    public function test_approval_snapshot_serializes_with_mapping_mutation(): void
    {
        $this->requireIsolatedMysql();
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $first = Risk::factory()->create();
        $second = Risk::factory()->create();
        app(PolicyRevisionContextManager::class)->attachRisk($policy, $first);
        $revision = app(PolicyRevisionManager::class)->submit($policy, $owner, [
            'change_summary' => 'Approve the stable mapping graph.',
            'proposed_effective_date' => now()->toDateString(),
        ]);

        $marker = tempnam(sys_get_temp_dir(), 'policy-revision-lock-');
        unlink($marker);
        $approval = $this->fork(function () use ($revision, $reviewer, $marker): void {
            $manager = new class($marker) extends PolicyRevisionManager
            {
                public function __construct(private readonly string $marker) {}

                protected function snapshot(Policy $policy, ?string $effectiveDate, bool $lock): array
                {
                    $snapshot = parent::snapshot($policy, $effectiveDate, $lock);
                    if ($lock) {
                        file_put_contents($this->marker, 'locked');
                        usleep(750_000);
                    }

                    return $snapshot;
                }
            };
            $manager->review(
                PolicyRevision::query()->findOrFail($revision->id),
                User::query()->findOrFail($reviewer->id),
                ['decision' => 'approved', 'review_summary' => 'Approved under a serialized graph lock.'],
            );
        });
        $this->waitForMarker($marker);
        $mapping = $this->fork(fn () => app(PolicyRevisionContextManager::class)
            ->attachRisk(Policy::query()->findOrFail($policy->id), Risk::query()->findOrFail($second->id)));

        $this->assertSame([0, 0], $this->waitFor([$approval, $mapping]));
        DB::disconnect();
        DB::reconnect();
        $approved = PolicyRevision::query()->findOrFail($revision->id);
        $this->assertCount(1, $approved->policy_snapshot['risks']);
        $this->assertSame(2, DB::table('policy_risk')->where('policy_id', $policy->id)->count());
        $this->assertSame('revision_required', Policy::query()->findOrFail($policy->id)->revision_governance_status);

        @unlink($marker);
        DB::table('policy_revision_reviews')->where('policy_revision_id', $revision->id)->delete();
        DB::table('policy_revisions')->where('policy_id', $policy->id)->delete();
        DB::table('policy_risk')->where('policy_id', $policy->id)->delete();
        DB::table('policies')->where('id', $policy->id)->delete();
        DB::table('risks')->whereIn('id', [$first->id, $second->id])->delete();
        DB::table('users')->whereIn('id', [$owner->id, $reviewer->id])->delete();
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL governance database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the policy revision concurrency test');
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
        $this->assertFileExists($marker, 'Approval worker did not reach the locked snapshot boundary.');
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
