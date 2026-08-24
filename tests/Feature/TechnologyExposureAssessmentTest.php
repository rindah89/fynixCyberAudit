<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\ImplementationStatus;
use App\Enums\RiskDomain;
use App\Filament\Exports\TechnologyExposureAssessmentExporter;
use App\Filament\Resources\RiskPortfolioResource\Pages\ViewRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\TechnologyExposureAssessmentsRelationManager;
use App\Models\Asset;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\Risk;
use App\Models\TechnologyExposureAssessment;
use App\Models\User;
use App\Services\TechnologyExposureAssessmentManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TechnologyExposureAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_records_versioned_assessment_with_server_scores_and_context_snapshot(): void
    {
        $manager = $this->manager();
        [$risk, $asset, $implementation, $control] = $this->technologyRisk($manager);
        Sanctum::actingAs($manager);
        $firstId = $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", $this->payload($asset))->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.inherent_score', 20)->assertJsonPath('data.residual_score', 12)
            ->assertJsonPath('data.state', 'above_appetite')->assertJsonPath('data.assessed_by', $manager->id)->json('data.id');
        $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", array_merge($this->payload($asset), ['residual_likelihood' => 2, 'residual_impact' => 3]))
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.state', 'within_appetite');

        $assessment = TechnologyExposureAssessment::query()->findOrFail($firstId);
        $this->assertSame($asset->id, $assessment->asset_snapshot['id']);
        $this->assertSame($control->id, data_get($assessment->governance_snapshot, 'implementations.0.controls.0.id'));
        $assetName = $assessment->asset_snapshot['name'];
        $asset->update(['name' => 'Renamed live asset']);
        $implementation->update(['title' => 'Changed implementation']);
        $this->assertSame($assetName, $assessment->fresh()->asset_snapshot['name']);
        $this->getJson("/api/risks/{$risk->id}/technology-exposure-assessments?per_page=1")->assertOk()->assertJsonPath('data.0.version', 2)->assertJsonPath('total', 2);
        $this->travelTo(now()->addMonths(2));
        $this->assertSame('review_overdue', $assessment->fresh()->schedule_status);
        $this->travelBack();
        foreach ([fn () => $assessment->update(['title' => 'Rewritten']), fn () => $assessment->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Assessment history was mutable.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        $factoryAssessment = TechnologyExposureAssessment::factory()->create();
        $this->assertSame($factoryAssessment->asset_id_snapshot, $factoryAssessment->asset_snapshot['id']);
        $this->assertSame($factoryAssessment->risk_id, data_get($factoryAssessment->governance_snapshot, 'risk.id'));
        $this->assertSame(hash('sha256', $factoryAssessment->governance_snapshot_json), $factoryAssessment->governance_fingerprint);
    }

    public function test_assessment_requires_current_technology_context_mapped_asset_and_valid_scores(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);
        $enterprise = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $otherAsset = Asset::factory()->create(['asset_tag' => 'AST-OTHER', 'name' => 'Other asset']);
        $this->postJson("/api/risks/{$enterprise->id}/technology-exposure-assessments", $this->payload($otherAsset))->assertUnprocessable()->assertJsonValidationErrors('risk_id');
        [$risk, $asset] = $this->technologyRisk($manager);
        $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", $this->payload($otherAsset))->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", array_merge($this->payload($asset), ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'residual_likelihood' => 5, 'residual_impact' => 5]))->assertUnprocessable()->assertJsonValidationErrors('residual_likelihood');
        try {
            app(TechnologyExposureAssessmentManager::class)->assess($risk, $manager, array_merge($this->payload($asset), ['inherent_likelihood' => 99]));
            $this->fail('Direct service accepted an out-of-range score.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inherent_likelihood', $exception->errors());
        }
        $asset->update(['is_active' => false]);
        $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", $this->payload($asset))->assertUnprocessable()->assertJsonValidationErrors('asset_id');
    }

    public function test_owner_inspects_read_only_history_and_outsider_is_denied(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        [$risk, $asset] = $this->technologyRisk($owner);
        $assessment = app(TechnologyExposureAssessmentManager::class)->assess($risk, $manager, $this->payload($asset));
        Sanctum::actingAs($owner);
        $this->getJson("/api/risks/{$risk->id}/technology-exposure-assessments")->assertOk()->assertJsonPath('data.0.id', $assessment->id);
        $this->postJson("/api/risks/{$risk->id}/technology-exposure-assessments", $this->payload($asset))->assertForbidden();
        $this->actingAs($owner, 'web');
        Livewire::test(TechnologyExposureAssessmentsRelationManager::class, ['ownerRecord' => $risk, 'pageClass' => ViewRiskPortfolio::class])->assertCanSeeTableRecords([$assessment])->assertTableActionHidden('assess')->assertTableActionVisible('inspect', $assessment);
        $controlCode = data_get($assessment->governance_snapshot, 'implementations.0.controls.0.code');
        $implementationDetails = data_get($assessment->governance_snapshot, 'implementations.0.details');
        $this->view('filament.technology-exposure-assessment', ['assessment' => $assessment])->assertSee($assessment->threat_scenario)->assertSee($assessment->vulnerability_description)->assertSee($assessment->source_reference)->assertSee($controlCode)->assertSee($implementationDetails)->assertSee($assessment->governance_fingerprint);
        $columns = collect(TechnologyExposureAssessmentExporter::getColumns())->map->getName();
        $this->assertArrayHasKey('governance_fingerprint', $assessment->getAttributes());
        $this->assertContains('residual_score', $columns);
        $this->assertContains('governance_fingerprint', $columns);
        $this->assertContains('asset_snapshot_json', $columns);
        $this->assertContains('governance_snapshot_json', $columns);
        $this->assertStringContainsString($controlCode, $assessment->governance_snapshot_json);

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/risks/{$risk->id}/technology-exposure-assessments")->assertForbidden();
        try {
            app(TechnologyExposureAssessmentManager::class)->assess($risk, $outsider, $this->payload($asset));
            $this->fail('Unauthorized service assessment succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    private function technologyRisk(User $owner): array
    {
        $risk = Risk::factory()->create(['domain' => RiskDomain::Technology]);
        $asset = Asset::factory()->create(['is_active' => true, 'asset_tag' => 'AST-TECH-1', 'name' => 'Technology asset']);
        $implementation = Implementation::factory()->create(['status' => ImplementationStatus::FULL]);
        $control = Control::factory()->create(['applicability' => Applicability::APPLICABLE]);
        $implementation->controls()->attach($control);
        $risk->assets()->attach($asset);
        $risk->implementations()->attach($implementation);
        $risk->governanceProfile()->create(['owner_id' => $owner->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly', 'next_review_at' => now()->addQuarter()]);

        return [$risk->load('governanceProfile'), $asset, $implementation, $control];
    }

    private function payload(Asset $asset): array
    {
        return ['asset_id' => $asset->id, 'exposure_type' => 'vulnerability', 'title' => 'Internet-facing component exposure', 'threat_scenario' => 'An external actor exploits the exposed component.', 'vulnerability_reference' => 'CVE-2026-12345', 'vulnerability_description' => 'A deliberately reported weakness affects the mapped asset.', 'source_reference' => 'SCAN-2026-08-24', 'inherent_likelihood' => 4, 'inherent_impact' => 5, 'residual_likelihood' => 3, 'residual_impact' => 4, 'recommended_response' => 'Patch and retest the component.', 'review_due_at' => today()->addMonth()->toDateString()];
    }
}
