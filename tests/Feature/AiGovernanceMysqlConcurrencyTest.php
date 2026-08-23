<?php

namespace Tests\Feature;

use App\AiGovernance\AiGovernanceManager;
use App\Enums\AiGovernanceDecisionType;
use App\Enums\AiMonitoringOutcome;
use App\Models\AiRiskAssessment;
use App\Models\AiSystem;
use App\Models\AiUseCase;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiGovernanceMysqlConcurrencyTest extends TestCase
{
    public function test_monitoring_snapshot_serializes_with_concurrent_mapping_change(): void
    {
        $this->requireIsolatedMysql();
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->givePermissionTo('Manage AI Governance');
        $system = AiSystem::factory()->create(['owner_id' => $actor->id]);
        $useCase = AiUseCase::factory()->create(['ai_system_id' => $system->id, 'owner_id' => $actor->id]);
        AiRiskAssessment::factory()->create(['ai_use_case_id' => $useCase->id, 'assessor_id' => $actor->id]);
        $control = Control::factory()->create();
        $firstRisk = Risk::factory()->create();
        $secondRisk = Risk::factory()->create();
        $useCase->controls()->attach($control);
        $useCase->risks()->attach($firstRisk);
        app(AiGovernanceManager::class)->decide($useCase, $actor, AiGovernanceDecisionType::Approved, [
            'rationale' => 'The approved graph is suitable for concurrency verification.',
            'next_monitoring_at' => now()->addMonth(),
        ]);

        $marker = tempnam(sys_get_temp_dir(), 'ai-governance-lock-');
        unlink($marker);
        $monitor = $this->fork(function () use ($useCase, $actor, $marker): void {
            $manager = new class($marker) extends AiGovernanceManager
            {
                public function __construct(private readonly string $marker) {}

                protected function lockGovernanceGraph(AiUseCase $useCase): void
                {
                    parent::lockGovernanceGraph($useCase);
                    file_put_contents($this->marker, 'locked');
                    usleep(750_000);
                }
            };
            $manager->monitor(
                AiUseCase::query()->findOrFail($useCase->id),
                User::query()->findOrFail($actor->id),
                AiMonitoringOutcome::Satisfactory,
                ['performance_summary' => 'Snapshot remained stable while the graph lock was held.', 'next_review_at' => now()->addMonth()],
            );
        });
        $this->waitForMarker($marker);
        $mapping = $this->fork(function () use ($useCase, $secondRisk): void {
            DB::transaction(function () use ($useCase, $secondRisk): void {
                $locked = AiUseCase::query()->lockForUpdate()->findOrFail($useCase->id);
                $locked->risks()->syncWithoutDetaching([$secondRisk->id]);
            });
        });

        $this->assertSame([0, 0], $this->waitFor([$monitor, $mapping]));
        DB::disconnect();
        DB::reconnect();
        $review = DB::table('ai_monitoring_reviews')->where('ai_use_case_id', $useCase->id)->first();
        $this->assertNotNull($review);
        $this->assertSame(2, DB::table('ai_use_case_risk')->where('ai_use_case_id', $useCase->id)->count());
        $this->assertSame('reapproval_required', AiUseCase::query()->findOrFail($useCase->id)->governance_status);
        $this->assertSame(
            DB::table('ai_governance_decisions')->where('ai_use_case_id', $useCase->id)->value('governance_fingerprint'),
            $review->governance_fingerprint,
        );

        @unlink($marker);
        $this->cleanup($useCase->id, $useCase->ai_system_id, [$firstRisk->id, $secondRisk->id], $control->id, $actor->id);
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_hierarchy_test_')) {
            $this->markTestSkipped('isolated MySQL governance database required');
        }
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the AI governance concurrency test');
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
        $this->assertFileExists($marker, 'Monitoring worker did not reach the locked snapshot boundary.');
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

    /** @param list<int> $riskIds */
    private function cleanup(int $useCaseId, int $systemId, array $riskIds, int $controlId, int $actorId): void
    {
        DB::table('ai_monitoring_reviews')->where('ai_use_case_id', $useCaseId)->delete();
        DB::table('ai_governance_decisions')->where('ai_use_case_id', $useCaseId)->delete();
        DB::table('ai_risk_assessments')->where('ai_use_case_id', $useCaseId)->delete();
        DB::table('ai_use_case_control')->where('ai_use_case_id', $useCaseId)->delete();
        DB::table('ai_use_case_risk')->where('ai_use_case_id', $useCaseId)->delete();
        AiUseCase::query()->whereKey($useCaseId)->delete();
        AiSystem::query()->whereKey($systemId)->delete();
        Control::query()->whereKey($controlId)->delete();
        Risk::query()->whereKey($riskIds)->delete();
        User::query()->whereKey($actorId)->delete();
    }
}
