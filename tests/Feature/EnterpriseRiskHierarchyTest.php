<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Filament\Exports\RiskExporter;
use App\Filament\Resources\RiskPortfolioResource;
use App\Mcp\EntityConfig;
use App\Mcp\Tools\InspectRiskPortfolioTool;
use App\Models\Risk;
use App\Models\RiskHierarchyChange;
use App\Models\User;
use App\Services\EnterpriseRiskHierarchy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseRiskHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_builds_a_cycle_safe_enterprise_hierarchy(): void
    {
        $manager = $this->manager();
        [$root, $businessUnit, $scenario] = $this->enterpriseRisks($manager);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$businessUnit->id}/parent", ['parent_risk_id' => $root->id])
            ->assertOk()->assertJsonPath('data.parent.id', $root->id);
        $change = RiskHierarchyChange::query()->where('risk_id', $businessUnit->id)->firstOrFail();
        $this->assertSame($manager->id, $change->changed_by);
        $this->assertSame($root->id, $change->parent_risk_id);
        $this->putJson("/api/risks/{$scenario->id}/parent", ['parent_risk_id' => $businessUnit->id])
            ->assertOk()->assertJsonPath('data.parent.id', $businessUnit->id);

        $this->putJson("/api/risks/{$root->id}/parent", ['parent_risk_id' => $scenario->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_risk_id']);
        $this->putJson("/api/risks/{$root->id}/parent", ['parent_risk_id' => $root->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_risk_id']);

        $businessUnit->update(['is_active' => false]);
        $this->putJson("/api/risks/{$businessUnit->id}/parent", ['parent_risk_id' => null])
            ->assertOk()->assertJsonPath('data.parent', null);
        $this->assertDatabaseHas('risk_hierarchy_mutexes', ['id' => 1]);
        $this->assertDatabaseCount('risk_hierarchy_changes', 3);

        $this->expectException(\LogicException::class);
        $change->update(['parent_risk_id' => null]);
    }

    public function test_hierarchy_rejects_non_enterprise_or_ungoverned_nodes(): void
    {
        $manager = $this->manager();
        [$enterprise] = $this->enterpriseRisks($manager, 1);
        $operational = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $ungoverned = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$operational->id}/parent", ['parent_risk_id' => $enterprise->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['risk']);
        $this->putJson("/api/risks/{$enterprise->id}/parent", ['parent_risk_id' => $ungoverned->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_risk_id']);
    }

    public function test_rollup_aggregates_current_active_exposure_across_all_descendants(): void
    {
        $manager = $this->manager();
        [$root, $child, $grandchild] = $this->enterpriseRisks($manager);
        $root->update(['residual_likelihood' => 2, 'residual_impact' => 2]);
        $child->update(['residual_likelihood' => 3, 'residual_impact' => 3]);
        $grandchild->update(['residual_likelihood' => 4, 'residual_impact' => 4]);
        app(EnterpriseRiskHierarchy::class)->assignParent($child, $root->id, $manager);
        app(EnterpriseRiskHierarchy::class)->assignParent($grandchild, $child->id, $manager);
        Sanctum::actingAs($manager);

        $this->getJson("/api/risks/{$root->id}/rollup")
            ->assertOk()
            ->assertJsonPath('data.root_risk_id', $root->id)
            ->assertJsonPath('data.risk_count', 3)
            ->assertJsonPath('data.descendant_count', 2)
            ->assertJsonPath('data.residual_score_sum', 29)
            ->assertJsonPath('data.residual_score_average', 9.67)
            ->assertJsonPath('data.residual_score_maximum', 16)
            ->assertJsonPath('data.above_appetite_count', 2)
            ->assertJsonPath('data.score_band_counts.critical', 0)
            ->assertJsonPath('data.score_band_counts.high', 1)
            ->assertJsonPath('data.score_band_counts.medium', 1)
            ->assertJsonPath('data.score_band_counts.low', 1);

        $grandchild->update(['is_active' => false]);
        $this->getJson("/api/risks/{$root->id}/rollup")
            ->assertOk()->assertJsonPath('data.risk_count', 2)
            ->assertJsonPath('data.residual_score_sum', 13);

        $root->update(['is_active' => false]);
        $this->getJson("/api/risks/{$root->id}/rollup")
            ->assertOk()->assertJsonPath('data.risk_count', 1)
            ->assertJsonPath('data.residual_score_sum', 9);
    }

    public function test_owner_can_read_assigned_rollup_but_cannot_change_hierarchy(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        [$root, $child] = $this->enterpriseRisks($manager, 2, $owner);
        $child->governanceProfile()->update(['owner_id' => User::factory()->create()->id]);
        app(EnterpriseRiskHierarchy::class)->assignParent($child, $root->id, $manager);

        Sanctum::actingAs($owner);
        $this->getJson("/api/risks/{$root->id}/rollup")->assertOk()->assertJsonPath('data.risk_count', 2);
        $this->actingAs($owner);
        $mcp = json_decode((string) (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $root->id]))->content(), true);
        $this->assertSame([], $mcp['risk']['child_risk_ids']);
        $this->assertSame([], $mcp['risk']['hierarchy_changes']);
        $this->assertSame(2, $mcp['risk']['enterprise_rollup']['risk_count']);

        [$foreignRoot] = $this->enterpriseRisks($manager, 1, User::factory()->create());
        [$ownedChild] = $this->enterpriseRisks($manager, 1, $owner);
        app(EnterpriseRiskHierarchy::class)->assignParent($ownedChild, $foreignRoot->id, $manager);
        $restrictedParent = json_decode((string) (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $ownedChild->id]))->content(), true);
        $this->assertTrue($restrictedParent['risk']['has_parent']);
        $this->assertNull($restrictedParent['risk']['parent_risk_id']);
        $this->putJson("/api/risks/{$child->id}/parent", ['parent_risk_id' => null])->assertForbidden();

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/risks/{$root->id}/rollup")->assertForbidden();
    }

    public function test_workspace_and_export_expose_hierarchy_without_lazy_loading(): void
    {
        $manager = $this->manager();
        [$root, $child] = $this->enterpriseRisks($manager, 2);
        app(EnterpriseRiskHierarchy::class)->assignParent($child, $root->id, $manager);
        $this->actingAs($manager);

        $record = RiskPortfolioResource::getEloquentQuery()->findOrFail($root->id);
        $this->assertTrue($record->relationLoaded('parentRisk'));
        $this->assertTrue($record->relationLoaded('childRisks'));
        $this->assertSame(1, $record->childRisks->count());

        $columns = collect(RiskExporter::getColumns())->map->getName();
        $this->assertContains('parentRisk.code', $columns);
        $this->assertContains('child_risks_count', $columns);
        $exported = RiskExporter::modifyQuery(Risk::query()->whereKey($child))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('parentRisk'));
        $this->assertEquals($root->code, $exported->parentRisk->code);

        EntityConfig::clearCache();
        $config = EntityConfig::get('risk');
        $this->assertArrayNotHasKey('parent_risk_id', $config['create_fields']);
        $this->assertArrayNotHasKey('parent_risk_id', $config['update_fields']);
    }

    public function test_hierarchy_history_preserves_referenced_risks_from_generic_deletion(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo('Delete Risks');
        [$root, $child] = $this->enterpriseRisks($manager, 2);
        app(EnterpriseRiskHierarchy::class)->assignParent($child, $root->id, $manager);
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/risks/{$child->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Risks with current or historical enterprise hierarchy links cannot be deleted.');
        $this->assertDatabaseHas('risks', ['id' => $child->id]);
    }

    public function test_rollup_depth_limit_has_stable_rest_and_mcp_output(): void
    {
        $manager = $this->manager();
        [$root] = $this->enterpriseRisks($manager, 1);
        $descendants = Risk::factory()->count(101)->create(['domain' => RiskDomain::Enterprise]);
        $parentId = $root->id;
        // Boundary fixture intentionally bypasses the governed writer to build an otherwise unreachable over-limit graph.
        foreach ($descendants as $descendant) {
            DB::table('risks')->where('id', $descendant->id)->update(['parent_risk_id' => $parentId]);
            $parentId = $descendant->id;
        }
        Sanctum::actingAs($manager);

        $this->getJson("/api/risks/{$root->id}/rollup")
            ->assertUnprocessable()->assertJsonValidationErrors(['hierarchy']);
        $bounded = app(EnterpriseRiskHierarchy::class)->boundedRollup($root);
        $this->assertFalse($bounded['available']);
        $this->assertStringContainsString('supported depth', $bounded['error']);

        $this->actingAs($manager);
        $mcp = json_decode((string) (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $root->id]))->content(), true);
        $this->assertFalse($mcp['risk']['enterprise_rollup']['available']);
        $this->assertStringContainsString('supported depth', $mcp['risk']['enterprise_rollup']['error']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    /** @return list<Risk> */
    private function enterpriseRisks(User $manager, int $count = 3, ?User $owner = null): array
    {
        return Risk::factory()->count($count)->create(['domain' => RiskDomain::Enterprise])->each(function (Risk $risk) use ($manager, $owner): void {
            $risk->governanceProfile()->create([
                'owner_id' => ($owner ?? $manager)->id,
                'appetite_threshold' => 8,
                'review_frequency' => 'quarterly',
                'strategic_objective' => 'Protect enterprise value.',
                'next_review_at' => now()->addQuarter(),
            ]);
        })->all();
    }
}
