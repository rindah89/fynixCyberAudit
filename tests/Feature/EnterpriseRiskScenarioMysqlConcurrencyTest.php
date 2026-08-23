<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Models\Risk;
use App\Models\User;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\EnterpriseRiskScenarioAnalyzer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnterpriseRiskScenarioMysqlConcurrencyTest extends TestCase
{
    public function test_concurrent_scenarios_receive_distinct_monotonic_versions(): void
    {
        $this->requireIsolatedMysql();
        $actor = User::factory()->create();
        $root = $this->governedRisk($actor);

        $children = [];
        foreach (['Concurrent A', 'Concurrent B'] as $name) {
            $children[] = $this->fork(function () use ($root, $actor, $name): void {
                app(EnterpriseRiskScenarioAnalyzer::class)->analyze(
                    Risk::query()->findOrFail($root->id),
                    User::query()->findOrFail($actor->id),
                    $this->payload($root->id, $name),
                );
            });
        }

        $this->assertSame([0, 0], $this->waitFor($children));
        DB::disconnect();
        DB::reconnect();
        $this->assertSame([1, 2], DB::table('enterprise_risk_scenarios')->where('root_risk_id', $root->id)->orderBy('version')->pluck('version')->map(fn ($version): int => (int) $version)->all());
        $this->cleanup([$root->id], $actor->id);
    }

    public function test_scenario_snapshot_is_consistent_with_a_concurrent_hierarchy_change(): void
    {
        $this->requireIsolatedMysql();
        $actor = User::factory()->create();
        $root = $this->governedRisk($actor);
        $child = $this->governedRisk($actor);
        app(EnterpriseRiskHierarchy::class)->assignParent($child, $root->id, $actor);

        $workers = [
            $this->fork(function () use ($root, $actor): void {
                app(EnterpriseRiskScenarioAnalyzer::class)->analyze(
                    Risk::query()->findOrFail($root->id),
                    User::query()->findOrFail($actor->id),
                    $this->payload($root->id, 'Concurrent hierarchy snapshot'),
                );
            }),
            $this->fork(function () use ($child, $actor): void {
                app(EnterpriseRiskHierarchy::class)->assignParent(
                    Risk::query()->findOrFail($child->id),
                    null,
                    User::query()->findOrFail($actor->id),
                );
            }),
        ];

        $this->assertSame([0, 0], $this->waitFor($workers));
        DB::disconnect();
        DB::reconnect();
        $scenario = DB::table('enterprise_risk_scenarios')->where('root_risk_id', $root->id)->first();
        $items = DB::table('enterprise_risk_scenario_items')->where('enterprise_risk_scenario_id', $scenario->id)->get()->keyBy('risk_id');
        $this->assertContains((int) $scenario->risk_count, [1, 2]);
        $this->assertCount((int) $scenario->risk_count, $items);
        if ((int) $scenario->risk_count === 2) {
            $this->assertSame($root->id, (int) $items->get($child->id)->parent_risk_id_snapshot);
        } else {
            $this->assertFalse($items->has($child->id));
        }
        $this->cleanup([$root->id, $child->id], $actor->id);
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL hierarchy database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the scenario concurrency test');
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

    private function governedRisk(User $owner): Risk
    {
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $risk->governanceProfile()->create([
            'owner_id' => $owner->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly',
            'strategic_objective' => 'Protect enterprise value.', 'next_review_at' => now()->addQuarter(),
        ]);

        return $risk;
    }

    private function payload(int $riskId, string $name): array
    {
        return [
            'name' => $name, 'narrative' => 'Concurrent deterministic stress analysis.',
            'horizon_months' => 12, 'probability_band' => 'possible',
            'adjustments' => [['risk_id' => $riskId, 'likelihood_shift' => 1, 'impact_shift' => 0]],
        ];
    }

    /** @param list<int> $riskIds */
    private function cleanup(array $riskIds, int $actorId): void
    {
        $scenarioIds = DB::table('enterprise_risk_scenarios')->whereIn('root_risk_id', $riskIds)->pluck('id');
        DB::table('enterprise_risk_scenario_items')->whereIn('enterprise_risk_scenario_id', $scenarioIds)->delete();
        DB::table('enterprise_risk_scenarios')->whereIn('id', $scenarioIds)->delete();
        DB::table('risk_hierarchy_changes')->whereIn('risk_id', $riskIds)->delete();
        Risk::query()->whereKey($riskIds)->update(['parent_risk_id' => null]);
        DB::table('risk_governance_profiles')->whereIn('risk_id', $riskIds)->delete();
        Risk::query()->whereKey($riskIds)->delete();
        User::query()->whereKey($actorId)->delete();
    }
}
