<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Models\Risk;
use App\Models\User;
use App\Services\EnterpriseRiskHierarchy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnterpriseRiskHierarchyMysqlConcurrencyTest extends TestCase
{
    public function test_opposite_concurrent_assignments_cannot_create_a_cycle(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL hierarchy database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the hierarchy concurrency test');
        }

        $actor = User::factory()->create();
        $first = $this->governedRisk($actor);
        $second = $this->governedRisk($actor);
        $assignments = [[$first->id, $second->id], [$second->id, $first->id]];
        $children = [];

        foreach ($assignments as [$riskId, $parentId]) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    DB::reconnect();
                    app(EnterpriseRiskHierarchy::class)->assignParent(
                        Risk::query()->findOrFail($riskId),
                        $parentId,
                        User::query()->findOrFail($actor->id),
                    );
                    exit(0);
                } catch (ValidationException) {
                    exit(10);
                } catch (\Throwable) {
                    exit(20);
                }
            }
            $children[] = $pid;
        }

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        sort($codes);
        $this->assertSame([0, 10], $codes);

        DB::disconnect();
        DB::reconnect();
        $this->assertSame(1, DB::table('risk_hierarchy_changes')->whereIn('risk_id', [$first->id, $second->id])->count());
        $this->assertFalse(
            Risk::query()->whereKey($first->id)->value('parent_risk_id') === $second->id
            && Risk::query()->whereKey($second->id)->value('parent_risk_id') === $first->id,
        );

        DB::table('risk_hierarchy_changes')->whereIn('risk_id', [$first->id, $second->id])->delete();
        Risk::query()->whereKey([$first->id, $second->id])->update(['parent_risk_id' => null]);
        DB::table('risk_governance_profiles')->whereIn('risk_id', [$first->id, $second->id])->delete();
        Risk::query()->whereKey([$first->id, $second->id])->delete();
        $actor->delete();
    }

    private function governedRisk(User $owner): Risk
    {
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $risk->governanceProfile()->create([
            'owner_id' => $owner->id,
            'appetite_threshold' => 8,
            'review_frequency' => 'quarterly',
            'strategic_objective' => 'Protect enterprise value.',
            'next_review_at' => now()->addQuarter(),
        ]);

        return $risk;
    }
}
