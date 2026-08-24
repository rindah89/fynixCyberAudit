<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\RiskIndicatorStatus;
use App\Filament\Exports\RiskIndicatorObservationExporter;
use App\Filament\Resources\RiskPortfolioResource\Pages\ViewRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\RiskIndicatorObservationsRelationManager;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\RiskIndicatorsRelationManager;
use App\Models\BusinessService;
use App\Models\Risk;
use App\Models\RiskIndicator;
use App\Models\RiskIndicatorObservation;
use App\Models\User;
use App\Services\RiskIndicatorManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiskIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_defines_indicator_and_server_derives_decimal_safe_observation_statuses(): void
    {
        $manager = $this->manager();
        $risk = $this->operationalRisk($manager);
        Sanctum::actingAs($manager);
        $indicatorId = $this->postJson("/api/risks/{$risk->id}/indicators", $this->definition())->assertCreated()->json('data.id');
        $indicator = RiskIndicator::query()->findOrFail($indicatorId);

        $this->postJson("/api/risk-indicators/{$indicator->id}/observations", ['observed_value' => '9007199254740.123456', 'notes' => 'Source reviewed.'])
            ->assertCreated()->assertJsonPath('data.status', 'critical')->assertJsonPath('data.observed_by', $manager->id);
        $this->assertSame(RiskIndicatorStatus::Critical, $indicator->fresh()->last_status);
        $this->assertNotNull($indicator->fresh()->next_due_at);
        $this->getJson("/api/risks/{$risk->id}/indicators?per_page=10")->assertOk()->assertJsonPath('data.0.observations_count', 1);
        $this->getJson("/api/risk-indicators/{$indicator->id}/observations?per_page=10")->assertOk()->assertJsonPath('data.0.status', 'critical')->assertJsonPath('total', 1);

        $observation = RiskIndicatorObservation::query()->firstOrFail();
        $this->putJson("/api/risk-indicators/{$indicator->id}", array_merge($this->definition(['owner_id' => $indicator->owner_id]), ['critical_threshold' => '20', 'is_active' => true]))
            ->assertOk()->assertJsonPath('data.critical_threshold', '20.000000');
        $this->assertSame('10.000000', $observation->fresh()->critical_threshold_snapshot);
        foreach ([fn () => $observation->update(['observed_value' => '0']), fn () => $observation->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Append-only observation history was mutated.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_threshold_direction_context_and_observation_time_are_validated(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);
        $enterprise = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $this->postJson("/api/risks/{$enterprise->id}/indicators", $this->definition())->assertUnprocessable()->assertJsonValidationErrors('risk_id');
        $risk = $this->operationalRisk($manager);
        $this->postJson("/api/risks/{$risk->id}/indicators", array_merge($this->definition(), ['critical_threshold' => '4']))->assertUnprocessable()->assertJsonValidationErrors('critical_threshold');
        $id = $this->postJson("/api/risks/{$risk->id}/indicators", array_merge($this->definition(), ['direction' => 'lower_is_worse', 'warning_threshold' => '95', 'critical_threshold' => '90']))->assertCreated()->json('data.id');
        $this->postJson("/api/risk-indicators/{$id}/observations", ['observed_value' => '89'])->assertCreated()->assertJsonPath('data.status', 'critical');
        $this->postJson("/api/risk-indicators/{$id}/observations", ['observed_value' => '96', 'observed_at' => now()->addHour()->toISOString()])->assertUnprocessable()->assertJsonValidationErrors('observed_at');
        try {
            app(RiskIndicatorManager::class)->observe(RiskIndicator::query()->findOrFail($id), $manager, ['observed_value' => 'not-a-number']);
            $this->fail('Direct service accepted an invalid numeric observation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('observed_value', $exception->errors());
        }
    }

    public function test_owner_records_and_inspects_observations_but_outsider_cannot(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = $this->operationalRisk($owner);
        $indicator = app(RiskIndicatorManager::class)->define($risk, $manager, $this->definition(['owner_id' => $owner->id]));
        Sanctum::actingAs($owner);
        $observationId = $this->postJson("/api/risk-indicators/{$indicator->id}/observations", ['observed_value' => '7.5', 'source_reference' => 'KRI-FILE-1'])->assertCreated()->assertJsonPath('data.status', 'warning')->json('data.id');
        $this->getJson("/api/risks/{$risk->id}/indicators")->assertOk()->assertJsonPath('data.0.id', $indicator->id);
        $observation = RiskIndicatorObservation::query()->findOrFail($observationId);
        $this->actingAs($owner, 'web');
        Livewire::test(RiskIndicatorsRelationManager::class, ['ownerRecord' => $risk, 'pageClass' => ViewRiskPortfolio::class])->assertCanSeeTableRecords([$indicator])->assertTableActionVisible('observe', $indicator)->assertTableActionHidden('define');
        Livewire::test(RiskIndicatorObservationsRelationManager::class, ['ownerRecord' => $risk, 'pageClass' => ViewRiskPortfolio::class])->assertCanSeeTableRecords([$observation])->assertTableActionVisible('inspect', $observation);
        $this->view('filament.risk-indicator-observation', ['observation' => $observation])
            ->assertSee($observation->warning_threshold_snapshot)->assertSee($observation->critical_threshold_snapshot)
            ->assertSee($observation->source_reference)->assertSee($observation->reason);
        $columns = collect(RiskIndicatorObservationExporter::getColumns())->map->getName();
        $this->assertContains('critical_threshold_snapshot', $columns);

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/risks/{$risk->id}/indicators")->assertForbidden();
        try {
            app(RiskIndicatorManager::class)->observe($indicator, $outsider, ['observed_value' => '1']);
            $this->fail('Unauthorized service call succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(RiskIndicatorManager::class)->update($indicator, $outsider, array_merge($this->definition(['owner_id' => $owner->id]), ['is_active' => false]));
            $this->fail('Unauthorized definition update succeeded.');
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

    private function operationalRisk(User $owner): Risk
    {
        $service = BusinessService::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $risk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $risk->governanceProfile()->create(['owner_id' => $owner->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly', 'business_service_id' => $service->id, 'next_review_at' => now()->addQuarter()]);

        return $risk->load('governanceProfile.businessService');
    }

    private function definition(array $overrides = []): array
    {
        return array_merge(['owner_id' => $overrides['owner_id'] ?? User::factory()->create()->id, 'code' => 'KRI-AVAIL', 'name' => 'Service outage minutes', 'unit' => 'minutes', 'direction' => 'higher_is_worse', 'warning_threshold' => '5', 'critical_threshold' => '10', 'frequency' => 'monthly', 'next_due_at' => now()->addMonth()->toISOString()], $overrides);
    }
}
