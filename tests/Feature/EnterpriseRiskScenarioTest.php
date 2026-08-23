<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Filament\Exports\RiskExporter;
use App\Filament\Resources\RiskPortfolioResource;
use App\Mcp\Tools\InspectRiskPortfolioTool;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Models\User;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\EnterpriseRiskScenarioAnalyzer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Yethee\Tiktoken\EncoderProvider;

class EnterpriseRiskScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_runs_versioned_scenario_with_server_derived_stress_results(): void
    {
        $manager = $this->manager();
        [$root, $child] = $this->hierarchy($manager);
        $root->update(['residual_likelihood' => 2, 'residual_impact' => 2]);
        $child->update(['residual_likelihood' => 3, 'residual_impact' => 3]);
        Sanctum::actingAs($manager);

        $firstResponse = $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => 1, 'impact_shift' => 0, 'rationale' => 'Demand volatility increases event frequency.'],
            ['risk_id' => $child->id, 'likelihood_shift' => 0, 'impact_shift' => 1, 'rationale' => 'Supplier concentration amplifies impact.'],
        ]) + ['version' => 99, 'stressed_score_sum' => 999])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.probability_band', 'possible')
            ->assertJsonPath('data.risk_count', 2)
            ->assertJsonPath('data.baseline_score_sum', 13)
            ->assertJsonPath('data.stressed_score_sum', 18)
            ->assertJsonPath('data.score_delta', 5)
            ->assertJsonPath('data.above_appetite_count', 1)
            ->assertJsonPath('data.stressed_band_counts.high', 1)
            ->assertJsonPath('data.stressed_band_counts.medium', 1)
            ->assertJsonCount(2, 'data.items');
        $firstScenarioId = $firstResponse->json('data.id');

        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => -1, 'impact_shift' => 0],
        ]))->assertCreated()->assertJsonPath('data.version', 2);

        $this->getJson("/api/risks/{$root->id}/scenarios?per_page=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
        $this->getJson("/api/enterprise-risk-scenarios/{$firstScenarioId}?item_per_page=1")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('items_pagination.total', 2);

        for ($version = 3; $version <= 11; $version++) {
            app(EnterpriseRiskScenarioAnalyzer::class)->analyze($root, $manager, array_replace($this->scenarioPayload([
                ['risk_id' => $root->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
            ]), ['name' => "Bounded history {$version}"]));
        }

        $this->actingAs($manager);
        $mcp = json_decode((string) (new InspectRiskPortfolioTool)->handle(new Request([
            'risk_id' => $root->id, 'scenario' => $firstScenarioId.':1',
        ]))->content(), true);
        $selected = $mcp['risk']['enterprise_scenario_detail'];
        $this->assertCount(10, $mcp['risk']['enterprise_scenarios']);
        $this->assertCount(2, $selected['items']);
        $this->assertSame(2, $selected['items_pagination']['total']);
    }

    public function test_scenario_rejects_adjustments_outside_the_active_enterprise_hierarchy(): void
    {
        $manager = $this->manager();
        [$root, $child] = $this->hierarchy($manager);
        $outside = $this->governedEnterpriseRisk($manager);
        $operational = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $outside->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['adjustments']);

        $child->update(['is_active' => false]);
        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $child->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['adjustments']);

        $this->postJson("/api/risks/{$operational->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $operational->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['risk']);
    }

    public function test_scenario_requires_a_material_adjustment_and_closed_inputs(): void
    {
        $manager = $this->manager();
        [$root] = $this->hierarchy($manager, 1);
        Sanctum::actingAs($manager);

        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => 0, 'impact_shift' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['adjustments']);

        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => 5, 'impact_shift' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['adjustments.0.likelihood_shift']);
    }

    public function test_scenario_snapshots_are_attributable_and_immutable(): void
    {
        $manager = $this->manager();
        [$root, $child] = $this->hierarchy($manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $child->id, 'likelihood_shift' => 1, 'impact_shift' => 1],
        ]))->assertCreated();

        $scenario = EnterpriseRiskScenario::query()->with('items')->firstOrFail();
        $item = $scenario->items->firstWhere('risk_id', $child->id);
        $this->assertSame($manager->id, $scenario->created_by);
        $this->assertSame($child->name, $item->risk_name_snapshot);
        $child->update(['name' => 'Changed after analysis', 'residual_likelihood' => 1]);
        $this->assertNotSame($child->fresh()->name, $item->fresh()->risk_name_snapshot);

        try {
            $item->update(['stressed_score' => 1]);
            $this->fail('Scenario items must be immutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $scenario->update(['name' => 'Rewritten scenario']);
    }

    public function test_owner_reads_summary_without_foreign_items_but_cannot_run_scenario(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        [$root, $child] = $this->hierarchy($manager, 2, $owner);
        $child->governanceProfile()->update(['owner_id' => User::factory()->create()->id]);
        Sanctum::actingAs($manager);
        $scenarioId = $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $child->id, 'likelihood_shift' => 1, 'impact_shift' => 1],
        ]))->assertCreated()->json('data.id');

        Sanctum::actingAs($owner);
        $this->getJson("/api/risks/{$root->id}/scenarios")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.created_by')->assertJsonMissingPath('data.0.hierarchy_snapshot');
        $this->getJson("/api/enterprise-risk-scenarios/{$scenarioId}")
            ->assertOk()->assertJsonPath('items_restricted', true)->assertJsonCount(0, 'data.items')
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.hierarchy_snapshot');
        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
        ]))->assertForbidden();

        $this->actingAs($owner);
        $mcp = json_decode((string) (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $root->id]))->content(), true);
        $this->assertCount(1, $mcp['risk']['enterprise_scenarios']);
        $this->assertArrayNotHasKey('items', $mcp['risk']['enterprise_scenarios'][0]);

        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Risks');
        $this->actingAs($reader);
        $this->assertTrue(RiskPortfolioResource::canViewAny());
        $this->assertTrue(RiskPortfolioResource::canView($root));
        $this->assertSame($root->id, RiskPortfolioResource::getEloquentQuery()->whereKey($root)->firstOrFail()->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/risks/{$root->id}/scenarios")->assertForbidden();
    }

    public function test_workspace_export_and_risk_lifecycle_expose_scenario_evidence(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo('Delete Risks');
        [$root] = $this->hierarchy($manager, 1);
        Sanctum::actingAs($manager);
        $this->postJson("/api/risks/{$root->id}/scenarios", $this->scenarioPayload([
            ['risk_id' => $root->id, 'likelihood_shift' => 1, 'impact_shift' => 0],
        ]))->assertCreated();
        $this->actingAs($manager);

        $record = RiskPortfolioResource::getEloquentQuery()->findOrFail($root->id);
        $this->assertTrue($record->relationLoaded('latestEnterpriseScenario'));
        $this->assertSame(1, $record->enterprise_scenarios_count);

        $columns = collect(RiskExporter::getColumns())->map->getName();
        $this->assertContains('enterprise_scenarios_count', $columns);
        $this->assertContains('latestEnterpriseScenario.stressed_score_sum', $columns);
        $exported = RiskExporter::modifyQuery(Risk::query()->whereKey($root))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('latestEnterpriseScenario'));
        $this->assertSame(1, $exported->enterprise_scenarios_count);

        Sanctum::actingAs($manager);
        $this->deleteJson("/api/risks/{$root->id}")->assertUnprocessable();
        $this->assertDatabaseHas('risks', ['id' => $root->id]);
    }

    public function test_mcp_scenario_detail_is_bounded_for_maximum_text_inputs(): void
    {
        $manager = $this->manager();
        $risks = $this->hierarchy($manager, 10);
        foreach ($risks as $risk) {
            $risk->update(['name' => $this->highEntropy(255)]);
        }
        $payload = [
            'name' => $this->highEntropy(255), 'narrative' => $this->highEntropy(30000),
            'horizon_months' => 120, 'probability_band' => 'possible',
            'adjustments' => collect($risks)->map(fn (Risk $risk): array => [
                'risk_id' => $risk->id, 'likelihood_shift' => 1, 'impact_shift' => 0,
                'rationale' => $this->highEntropy(30000),
            ])->all(),
        ];
        $scenario = app(EnterpriseRiskScenarioAnalyzer::class)->analyze($risks[0], $manager, $payload);

        $this->actingAs($manager);
        $content = (string) (new InspectRiskPortfolioTool)->handle(new Request([
            'risk_id' => $risks[0]->id, 'scenario' => (string) $scenario->id,
        ]))->content();
        $decoded = json_decode($content, true);
        $detail = $decoded['risk']['enterprise_scenario_detail'];
        $this->assertCount(5, $detail['items']);
        $this->assertLessThanOrEqual(120, strlen($detail['narrative_excerpt']));
        $this->assertLessThanOrEqual(48, strlen($detail['items'][0]['risk_name_snapshot']));
        $this->assertLessThanOrEqual(48, strlen($detail['items'][0]['rationale_excerpt']));
        $this->assertLessThanOrEqual(6000, strlen(json_encode(collect($decoded['risk'])->only([
            'enterprise_scenarios', 'enterprise_scenario_detail', 'enterprise_scenario_output_truncated',
        ])->all(), JSON_THROW_ON_ERROR)));

        $provider = new EncoderProvider;
        $this->assertLessThan(4000, count($provider->get('cl100k_base')->encode($content)));
        $this->assertLessThan(4000, count($provider->get('o200k_base')->encode($content)));
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    /** @return list<Risk> */
    private function hierarchy(User $manager, int $count = 2, ?User $rootOwner = null): array
    {
        $risks = collect([$this->governedEnterpriseRisk($rootOwner ?? $manager)]);
        while ($risks->count() < $count) {
            $risk = $this->governedEnterpriseRisk($manager);
            app(EnterpriseRiskHierarchy::class)->assignParent($risk, $risks->last()->id, $manager);
            $risks->push($risk);
        }

        return $risks->all();
    }

    private function governedEnterpriseRisk(User $owner): Risk
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

    private function scenarioPayload(array $adjustments): array
    {
        return [
            'name' => 'Supplier and demand shock',
            'narrative' => 'A deterministic stress case combining supplier disruption and demand volatility.',
            'horizon_months' => 12,
            'probability_band' => 'possible',
            'adjustments' => $adjustments,
        ];
    }

    private function highEntropy(int $length): string
    {
        return substr(base64_encode(random_bytes((int) ceil($length * 0.75) + 3)), 0, $length);
    }
}
