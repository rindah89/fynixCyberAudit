<?php

namespace Tests\Feature;

use App\Enums\OperationalLossEventCategory;
use App\Enums\RiskDomain;
use App\Filament\Exports\OperationalLossEventExporter;
use App\Filament\Resources\RiskPortfolioResource\Pages\ViewRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\OperationalLossEventsRelationManager;
use App\Models\BusinessService;
use App\Models\OperationalLossEvent;
use App\Models\Risk;
use App\Models\User;
use App\Services\OperationalLossEventManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationalLossEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_records_attributable_operational_loss_with_decimal_safe_net_amount(): void
    {
        $manager = $this->manager();
        $risk = $this->governedOperationalRisk($manager);
        Sanctum::actingAs($manager);

        $eventId = $this->postJson("/api/risks/{$risk->id}/operational-loss-events", array_merge($this->payload(), [
            'gross_loss' => '90071992547409.93',
            'recoveries' => '90071992547409.92',
        ]))->assertCreated()
            ->assertJsonPath('data.net_loss', '0.01')
            ->assertJsonPath('data.reported_by', $manager->id)
            ->assertJsonPath('data.business_service_id_snapshot', $risk->governanceProfile->business_service_id)
            ->json('data.id');

        $event = OperationalLossEvent::query()->findOrFail($eventId);
        $this->assertSame(OperationalLossEventCategory::BusinessDisruptionSystemFailure, $event->category);
        $this->assertSame('USD', $event->currency);
        $this->getJson("/api/risks/{$risk->id}/operational-loss-events?per_page=10")
            ->assertOk()->assertJsonPath('data.0.id', $event->id)->assertJsonPath('total', 1);

        $columns = collect(OperationalLossEventExporter::getColumns())->map->getName();
        $this->assertContains('net_loss', $columns);
        $exported = OperationalLossEventExporter::modifyQuery(OperationalLossEvent::query()->whereKey($event))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('risk'));
        $this->assertTrue($exported->relationLoaded('businessService'));
        $this->assertTrue($exported->relationLoaded('reporter'));
        $serviceName = $event->business_service_snapshot['name'];
        $event->businessService->update(['name' => 'Renamed after event']);
        $this->assertSame($serviceName, $event->fresh()->business_service_snapshot['name']);

        $this->expectException(\LogicException::class);
        $event->update(['summary' => 'Rewritten history']);
    }

    public function test_loss_event_requires_current_governed_operational_context_and_valid_amounts(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);
        $enterprise = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $this->postJson("/api/risks/{$enterprise->id}/operational-loss-events", $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('risk');

        $operational = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $this->postJson("/api/risks/{$operational->id}/operational-loss-events", $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('business_service');

        $risk = $this->governedOperationalRisk($manager);
        $this->postJson("/api/risks/{$risk->id}/operational-loss-events", array_merge($this->payload(), [
            'gross_loss' => '10.00', 'recoveries' => '10.01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('recoveries');
        $risk->governanceProfile->businessService->update(['status' => 'inactive']);
        $this->postJson("/api/risks/{$risk->id}/operational-loss-events", $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('business_service');
        $this->assertDatabaseCount('operational_loss_events', 0);
    }

    public function test_owner_has_read_only_loss_history_and_outsider_has_no_access(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = $this->governedOperationalRisk($owner);
        $event = app(OperationalLossEventManager::class)->record($risk, $manager, $this->payload());
        $factoryEvent = OperationalLossEvent::factory()->create();
        $this->assertSame($factoryEvent->business_service_id_snapshot, $factoryEvent->business_service_snapshot['id']);

        Sanctum::actingAs($owner);
        $this->getJson("/api/risks/{$risk->id}/operational-loss-events")->assertOk()->assertJsonPath('data.0.id', $event->id);
        $this->postJson("/api/risks/{$risk->id}/operational-loss-events", $this->payload())->assertForbidden();
        $this->actingAs($owner, 'web');
        Livewire::test(OperationalLossEventsRelationManager::class, [
            'ownerRecord' => $risk, 'pageClass' => ViewRiskPortfolio::class,
        ])->assertCanSeeTableRecords([$event])->assertTableActionHidden('record')
            ->assertTableActionVisible('inspect', $event);
        $this->view('filament.operational-loss-event', ['event' => $event])
            ->assertSee($event->summary)
            ->assertSee($event->source_reference)
            ->assertSee($event->detected_at->toDateString());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/risks/{$risk->id}/operational-loss-events")->assertForbidden();

        try {
            app(OperationalLossEventManager::class)->record($risk, User::factory()->create(), $this->payload());
            $this->fail('Unauthorized direct service recording was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('operational_loss_events', 2);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    private function governedOperationalRisk(User $owner): Risk
    {
        $service = BusinessService::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $risk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $risk->governanceProfile()->create([
            'owner_id' => $owner->id,
            'appetite_threshold' => 8,
            'review_frequency' => 'quarterly',
            'business_service_id' => $service->id,
            'next_review_at' => now()->addQuarter(),
        ]);

        return $risk->load('governanceProfile.businessService');
    }

    private function payload(): array
    {
        return [
            'category' => 'business_disruption_system_failure',
            'occurred_at' => today()->subDays(2)->toDateString(),
            'detected_at' => today()->subDay()->toDateString(),
            'summary' => 'A service disruption caused measurable operational loss.',
            'gross_loss' => '125000.00',
            'recoveries' => '25000.00',
            'currency' => 'USD',
            'source_reference' => 'LOSS-2026-0001',
        ];
    }
}
