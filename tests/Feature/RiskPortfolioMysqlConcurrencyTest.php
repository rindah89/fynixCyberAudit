<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Models\Asset;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use App\Models\User;
use App\Services\RiskPortfolioContextManager;
use App\Services\RiskPortfolioManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiskPortfolioMysqlConcurrencyTest extends TestCase
{
    public function test_review_snapshot_serializes_with_concurrent_asset_mapping(): void
    {
        $this->requireIsolatedMysql();
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->givePermissionTo('Manage Risk Portfolio');
        $risk = Risk::factory()->create([
            'domain' => RiskDomain::Enterprise,
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_risk' => 6,
        ]);
        $risk->governanceProfile()->create([
            'owner_id' => $actor->id,
            'appetite_threshold' => 8,
            'review_frequency' => 'quarterly',
            'strategic_objective' => 'Protect enterprise value.',
            'next_review_at' => now()->addQuarter(),
        ]);
        $asset = Asset::factory()->create(['asset_tag' => 'ERM-CONC-001', 'name' => 'Concurrent context asset']);

        $marker = tempnam(sys_get_temp_dir(), 'risk-portfolio-lock-');
        unlink($marker);
        $review = $this->fork(function () use ($risk, $actor, $marker): void {
            $manager = new class($marker) extends RiskPortfolioManager
            {
                public function __construct(private readonly string $marker) {}

                protected function lockContextGraph(Risk $risk, RiskGovernanceProfile $profile): void
                {
                    parent::lockContextGraph($risk, $profile);
                    file_put_contents($this->marker, 'locked');
                    usleep(750_000);
                }
            };
            $manager->review(
                Risk::query()->findOrFail($risk->id),
                User::query()->findOrFail($actor->id),
                RiskGovernanceDecision::Accepted,
                [
                    'summary' => 'The stable pre-mapping graph was reviewed.',
                    'next_review_at' => now()->addQuarter(),
                ],
            );
        });
        $this->waitForMarker($marker);
        $mapping = $this->fork(function () use ($risk, $asset): void {
            app(RiskPortfolioContextManager::class)->attachAsset(
                Risk::query()->findOrFail($risk->id),
                Asset::query()->findOrFail($asset->id),
            );
        });

        $this->assertSame([0, 0], $this->waitFor([$review, $mapping]));
        DB::disconnect();
        DB::reconnect();
        $storedReview = DB::table('risk_governance_reviews')->where('risk_id', $risk->id)->first();
        $this->assertNotNull($storedReview);
        $this->assertSame([], json_decode($storedReview->asset_ids_snapshot, true));
        $this->assertDatabaseHas('asset_risk', ['risk_id' => $risk->id, 'asset_id' => $asset->id]);
        $this->assertSame('re_review_required', Risk::query()->findOrFail($risk->id)->portfolio_governance_status);

        @unlink($marker);
        DB::table('risk_governance_reviews')->where('risk_id', $risk->id)->delete();
        DB::table('risk_governance_profiles')->where('risk_id', $risk->id)->delete();
        DB::table('asset_risk')->where('risk_id', $risk->id)->delete();
        Risk::query()->whereKey($risk->id)->delete();
        Asset::query()->whereKey($asset->id)->delete();
        User::query()->whereKey($actor->id)->delete();
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL governance database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the risk portfolio concurrency test');
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
        $this->assertFileExists($marker, 'Review worker did not reach the locked context boundary.');
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
