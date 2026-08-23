<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\ImplementationStatus;
use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Filament\Exports\RiskExporter;
use App\Filament\Resources\RiskPortfolioResource;
use App\Mcp\EntityConfig;
use App\Mcp\Tools\InspectRiskPortfolioTool;
use App\Models\Asset;
use App\Models\BusinessService;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\Risk;
use App\Models\RiskGovernanceIssue;
use App\Models\User;
use App\Services\RiskPortfolioManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RiskPortfolioGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        EntityConfig::clearCache();
    }

    public function test_enterprise_risk_profile_requires_strategic_context_and_supports_acceptance(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['strategic_objective']);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['strategic_objective' => 'Protect recurring revenue while entering regulated markets.'])
            ->assertOk()->assertJsonPath('data.appetite_threshold', 8)
            ->assertJsonPath('risk.portfolio_governance_status', 'review_required');

        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertCreated()->assertJsonPath('data.residual_score_snapshot', 6)
            ->assertJsonPath('data.appetite_threshold_snapshot', 8)
            ->assertJsonPath('risk.portfolio_governance_status', 'accepted');
    }

    public function test_operational_risk_profile_requires_business_service_context(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $service = BusinessService::factory()->create();
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['business_service_id']);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['business_service_id' => $service->id])
            ->assertOk()->assertJsonPath('data.business_service_id', $service->id);
    }

    public function test_technology_risk_requires_asset_and_control_implementation_context(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Technology, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['assets', 'implementations']);

        $risk->assets()->attach(Asset::factory()->create(['asset_tag' => 'TECH-001', 'name' => 'Payments API']));
        $incomplete = Implementation::factory()->create(['status' => ImplementationStatus::PARTIAL]);
        $incomplete->controls()->attach(Control::factory()->create(['applicability' => Applicability::APPLICABLE]));
        $risk->implementations()->attach($incomplete);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['implementations']);

        $implementation = Implementation::factory()->create(['status' => ImplementationStatus::FULL]);
        $implementation->controls()->attach(Control::factory()->create(['applicability' => Applicability::APPLICABLE]));
        $risk->implementations()->attach($implementation);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertOk()->assertJsonPath('risk.portfolio_governance_status', 'review_required');
        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertCreated()->assertJsonPath('risk.portfolio_governance_status', 'accepted');
        $implementation->controls()->detach();
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
    }

    public function test_risk_above_appetite_cannot_be_accepted_and_treatment_opens_issue(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 4, 'residual_impact' => 4]);
        Sanctum::actingAs($manager);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['strategic_objective' => 'Protect service margin.'])->assertOk();

        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertUnprocessable()->assertJsonValidationErrors(['decision']);
        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('mitigate'))
            ->assertCreated()->assertJsonPath('data.issue.status', 'open')
            ->assertJsonPath('risk.portfolio_governance_status', 'action_required');
        $issue = RiskGovernanceIssue::query()->where('risk_id', $risk->id)->firstOrFail();
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_type' => RiskGovernanceIssue::class, 'issue_id' => $issue->id, 'status' => 'open']);
    }

    public function test_material_risk_or_profile_change_requires_new_review(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);

        $risk->update(['residual_likelihood' => 3]);
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
        $risk->update(['residual_likelihood' => 2]);
        $this->assertSame('accepted', $risk->fresh()->portfolio_governance_status);
        $risk->governanceProfile->update(['appetite_threshold' => 7]);
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
    }

    public function test_reviews_are_append_only_and_attributable(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);
        $review = $risk->governanceReviews()->firstOrFail();

        $this->assertSame($manager->id, $review->reviewed_by);
        $this->assertSame('Protect recurring revenue.', $review->governance_snapshot['profile']['strategic_objective']);
        $risk->governanceProfile->update(['strategic_objective' => 'Changed objective.']);
        $this->assertSame('Protect recurring revenue.', $review->fresh()->governance_snapshot['profile']['strategic_objective']);
        $this->expectException(\LogicException::class);
        $review->delete();
    }

    public function test_governed_risk_domain_cannot_be_reclassified(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);

        $this->expectException(\LogicException::class);
        $risk->update(['domain' => RiskDomain::Operational]);
    }

    public function test_third_party_and_unclassified_risks_use_other_governance_workflows(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::ThirdParty]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        $this->assertSame('not_applicable', $risk->portfolio_governance_status);
    }

    public function test_owner_has_scoped_read_only_workspace(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $otherRisk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $owner->id, 'strategic_objective' => 'Protect margin.']));
        $otherRisk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $other->id, 'business_service_id' => BusinessService::factory()->create()->id]));

        $this->actingAs($owner)->get(RiskPortfolioResource::getUrl('index'))->assertOk();
        $this->get(RiskPortfolioResource::getUrl('view', ['record' => $risk]))->assertOk();
        $this->assertSame([$risk->id], RiskPortfolioResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_export_and_mcp_expose_portfolio_governance_evidence(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);
        $columns = collect(RiskExporter::getColumns())->map->getName();
        $this->assertContains('portfolio_governance_status', $columns);
        $this->assertContains('governanceProfile.owner.name', $columns);
        $this->assertContains('latestGovernanceReview.decision', $columns);
        $exported = RiskExporter::modifyQuery(Risk::query()->whereKey($risk))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('governanceProfile'));
        $this->assertSame('accepted', $exported->portfolio_governance_status);

        $config = EntityConfig::get('risk');
        $this->assertContains('governanceProfile', $config['detail_relations']);
        $this->assertContains('governanceReviews', $config['detail_relations']);

        $this->actingAs($manager);
        $response = (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $risk->id]));
        $payload = json_decode((string) $response->content(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('enterprise', $payload['risk']['domain']);
        $this->assertSame(8, $payload['risk']['profile']['appetite_threshold']);
        $this->assertSame('accepted', $payload['risk']['reviews'][0]['decision']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    private function profilePayload(): array
    {
        return ['owner_id' => User::factory()->create()->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly', 'next_review_at' => now()->addMonths(3), 'context_notes' => 'Reviewed by the accountable business owner.'];
    }

    private function reviewPayload(string $decision): array
    {
        return ['decision' => $decision, 'summary' => 'Current exposure and treatment were reviewed against appetite.', 'evidence_reference' => 'RISK-REVIEW-2026-Q3', 'next_review_at' => now()->addMonths(3)];
    }

    private function reviewedEnterpriseRisk(User $manager): Risk
    {
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $manager->id, 'strategic_objective' => 'Protect recurring revenue.']));
        app(RiskPortfolioManager::class)->review($risk, $manager, RiskGovernanceDecision::Accepted, $this->reviewPayload('accepted'));

        return $risk;
    }
}
