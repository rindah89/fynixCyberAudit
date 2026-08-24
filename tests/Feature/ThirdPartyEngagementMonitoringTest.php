<?php

namespace Tests\Feature;

use App\Enums\ThirdPartyRiskDecisionType;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementMonitoringObservation;
use App\Models\User;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThirdPartyEngagementMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_defines_an_indicator_and_owner_records_a_derived_observation(): void
    {
        [$engagement, $manager, $owner] = $this->activeEngagement();
        Sanctum::actingAs($manager);

        $indicatorId = $this->postJson("/api/third-party-engagements/{$engagement->id}/monitoring-indicators", [
            'code' => 'SLA-AVAILABILITY',
            'name' => 'Monthly availability',
            'description' => 'Contract availability measured from the provider report.',
            'category' => 'service_level',
            'unit' => '%',
            'direction' => 'lower_is_worse',
            'warning_threshold' => '99.900000',
            'critical_threshold' => '99.500000',
            'frequency_days' => 30,
            'owner_id' => $owner->id,
            'measurement_method' => 'Review the provider monthly service report.',
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.contract_review_snapshot.id', $engagement->contractRiskReviews()->latest('version')->value('id'))
            ->json('data.id');

        Sanctum::actingAs($owner);
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$indicatorId}/observations", [
            'observed_value' => '99.400000',
            'observed_at' => now()->startOfSecond()->toIso8601String(),
            'notes' => 'Availability missed the critical contractual threshold.',
            'source_reference' => 'PROVIDER-REPORT-2026-08',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'critical')
            ->assertJsonPath('indicator.monitoring_status', 'action_required');

        $this->getJson("/api/third-party-engagement-monitoring-indicators/{$indicatorId}/observations?per_page=100")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_reference', 'PROVIDER-REPORT-2026-08');
    }

    public function test_definition_versions_are_immutable_and_only_the_current_contract_bound_version_can_be_observed(): void
    {
        [$engagement, $manager, $owner] = $this->activeEngagement();
        Sanctum::actingAs($manager);
        $payload = ['code' => 'INCIDENT-MTTA', 'name' => 'Incident acknowledgement time', 'description' => 'Provider acknowledgement time.',
            'category' => 'security', 'unit' => 'minutes', 'direction' => 'higher_is_worse', 'warning_threshold' => '30', 'critical_threshold' => '60',
            'frequency_days' => 30, 'owner_id' => $owner->id, 'measurement_method' => 'Review retained provider incident reports.'];
        $first = $this->postJson("/api/third-party-engagements/{$engagement->id}/monitoring-indicators", $payload)->assertCreated()->json('data');
        $second = $this->postJson("/api/third-party-engagements/{$engagement->id}/monitoring-indicators", $payload + ['version' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $second = $this->postJson("/api/third-party-engagements/{$engagement->id}/monitoring-indicators", array_replace($payload, ['warning_threshold' => '20', 'critical_threshold' => '45']))
            ->assertCreated()->assertJsonPath('data.version', 2)->json('data');

        Sanctum::actingAs($owner);
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$first['id']}/observations", ['observed_value' => '15', 'observed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('indicator');
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$second['id']}/observations", ['observed_value' => '20', 'observed_at' => now()->startOfSecond()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.status', 'warning');

        $definition = ThirdPartyEngagementMonitoringIndicator::query()->findOrFail($second['id']);
        $definitionPayload = $definition->only(['code', 'name', 'description', 'category', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency_days', 'owner_id', 'measurement_method', 'third_party_engagement_id', 'version', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'defined_by']);
        $definitionPayload['category'] = $definition->category->value;
        $definitionPayload['direction'] = $definition->direction->value;
        $definitionPayload['defined_at'] = $definition->defined_at->toIso8601String();
        $this->assertSame($definition->fingerprint, hash('sha256', json_encode($definitionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $observation = ThirdPartyEngagementMonitoringObservation::query()->firstOrFail();
        $observationPayload = $observation->only(['third_party_engagement_monitoring_indicator_id', 'version', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'indicator_snapshot', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'observed_by']);
        $observationPayload['status'] = $observation->status->value;
        $observationPayload['observed_at'] = $observation->observed_at->toIso8601String();
        $observationPayload['recorded_at'] = $observation->recorded_at->toIso8601String();
        $this->assertSame($observation->fingerprint, hash('sha256', json_encode($observationPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $this->expectException(\LogicException::class);
        $definition->update(['name' => 'Rewritten']);
    }

    public function test_monitoring_is_active_contract_scoped_authorized_and_visible_as_complete_operator_evidence(): void
    {
        $review = ThirdPartyContractRiskReview::factory()->create();
        $approved = $review->engagement()->firstOrFail();
        $manager = User::query()->findOrFail($approved->approved_by);
        $manager->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']);
        Sanctum::actingAs($manager);
        $payload = ['code' => 'COMPLIANCE-FINDINGS', 'name' => 'Open assurance findings', 'category' => 'compliance', 'unit' => 'findings',
            'direction' => 'higher_is_worse', 'warning_threshold' => '1', 'critical_threshold' => '3', 'frequency_days' => 90,
            'owner_id' => $approved->business_owner_id, 'measurement_method' => 'Review the current assurance report and count unresolved findings.'];
        $this->postJson("/api/third-party-engagements/{$approved->id}/monitoring-indicators", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('engagement');

        app(ThirdPartyEngagementManager::class)->transition($manager, $approved, ['status' => 'active', 'summary' => 'Activated for monitoring.']);
        $indicatorId = $this->postJson("/api/third-party-engagements/{$approved->id}/monitoring-indicators", $payload + ['fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $indicatorId = $this->postJson("/api/third-party-engagements/{$approved->id}/monitoring-indicators", $payload)->assertCreated()->json('data.id');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$indicatorId}/observations", ['observed_value' => '4', 'observed_at' => now()->toIso8601String()])->assertForbidden();
        $this->getJson("/api/third-party-engagements/{$approved->id}/monitoring-indicators")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$indicatorId}/observations", ['observed_value' => '4', 'observed_at' => now()->startOfSecond()->toIso8601String(), 'notes' => 'Four findings remain open.', 'source_reference' => 'SOC2-2026'])
            ->assertCreated();
        $rendered = $this->view('filament.third-party-engagement', ['engagement' => $approved->fresh(['businessOwner', 'events.actor', 'contractRiskReviews.reviewer', 'monitoringIndicators.owner', 'monitoringIndicators.definer', 'monitoringIndicators.observations.observer'])]);
        $rendered->assertSee('Engagement monitoring evidence')->assertSee('COMPLIANCE-FINDINGS')->assertSee('Open assurance findings')
            ->assertSee('Four findings remain open.')->assertSee('SOC2-2026')->assertSee('Retained engagement, contract, and risk context');
    }

    public function test_factories_and_routine_rollback_retain_reconstructible_monitoring_evidence(): void
    {
        $observation = ThirdPartyEngagementMonitoringObservation::factory()->create();
        $indicator = $observation->indicator()->firstOrFail();
        $this->assertSame($indicator->owner_id, $observation->observed_by);
        $this->assertSame($indicator->contract_review_snapshot['id'], $observation->contract_review_snapshot['id']);

        $definitionPayload = $indicator->only(['code', 'name', 'description', 'category', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency_days', 'owner_id', 'measurement_method', 'third_party_engagement_id', 'version', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'defined_by']);
        $definitionPayload['category'] = $indicator->category->value;
        $definitionPayload['direction'] = $indicator->direction->value;
        $definitionPayload['defined_at'] = $indicator->defined_at->toIso8601String();
        $this->assertSame($indicator->fingerprint, hash('sha256', json_encode($definitionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $observationPayload = $observation->only(['third_party_engagement_monitoring_indicator_id', 'version', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'indicator_snapshot', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'observed_by']);
        $observationPayload['status'] = $observation->status->value;
        $observationPayload['observed_at'] = $observation->observed_at->toIso8601String();
        $observationPayload['recorded_at'] = $observation->recorded_at->toIso8601String();
        $this->assertSame($observation->fingerprint, hash('sha256', json_encode($observationPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $migration = include database_path('migrations/2026_08_24_770000_create_third_party_engagement_monitoring.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_monitoring_indicators', ['id' => $indicator->id, 'fingerprint' => $indicator->fingerprint]);
        $this->assertDatabaseHas('third_party_engagement_monitoring_observations', ['id' => $observation->id, 'fingerprint' => $observation->fingerprint]);
    }

    public function test_observation_rejects_stale_vendor_risk_and_contract_context(): void
    {
        [$engagement, $manager, $owner] = $this->activeEngagement();
        $indicator = app(ThirdPartyEngagementMonitoringManager::class)->define($manager, $engagement, [
            'code' => 'RISK-CONTEXT', 'name' => 'Risk context signal', 'category' => 'security', 'unit' => 'events',
            'direction' => 'higher_is_worse', 'warning_threshold' => '1', 'critical_threshold' => '2', 'frequency_days' => 30,
            'owner_id' => $owner->id, 'measurement_method' => 'Review provider risk events.',
        ]);
        $assessor = User::factory()->create();
        $decider = User::factory()->create();
        $assessor->givePermissionTo('Manage Third Party Risk');
        $decider->givePermissionTo('Manage Third Party Risk');
        $riskManager = app(ThirdPartyRiskManager::class);
        $vendor = $engagement->vendor()->firstOrFail();
        $riskManager->assess($vendor, $assessor, ['likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 3, 'residual_impact' => 4,
            'risk_categories' => ['cybersecurity'], 'assessment_summary' => 'Risk context materially changed.', 'treatment_summary' => 'Contract and monitoring require review.']);
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, ['rationale' => 'Updated risk decision.', 'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(3)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/third-party-engagement-monitoring-indicators/{$indicator->id}/observations", ['observed_value' => '1', 'observed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('contract_review');
        $this->assertDatabaseCount('third_party_engagement_monitoring_observations', 0);
    }

    public function test_expired_vendor_risk_approval_cannot_authorize_monitoring_writes(): void
    {
        [$engagement, $manager, $owner] = $this->activeEngagement();
        $decisionId = data_get($engagement->approval_snapshot, 'decision.id');
        DB::table('vendor_risk_decisions')->where('id', $decisionId)->update(['expires_at' => today()->subDay()->toDateString()]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/third-party-engagements/{$engagement->id}/monitoring-indicators", [
            'code' => 'EXPIRED-APPROVAL', 'name' => 'Expired approval signal', 'category' => 'security', 'unit' => 'events',
            'direction' => 'higher_is_worse', 'warning_threshold' => '1', 'critical_threshold' => '2', 'frequency_days' => 30,
            'owner_id' => $owner->id, 'measurement_method' => 'Review provider risk events.',
        ])->assertUnprocessable()->assertJsonValidationErrors('contract_review');
        $this->assertDatabaseCount('third_party_engagement_monitoring_indicators', 0);
    }

    /** @return array{ThirdPartyEngagement, User, User} */
    private function activeEngagement(): array
    {
        $review = ThirdPartyContractRiskReview::factory()->create();
        $engagement = $review->engagement()->firstOrFail();
        $manager = User::query()->findOrFail($engagement->approved_by);
        $manager->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']);
        app(ThirdPartyEngagementManager::class)->transition($manager, $engagement, [
            'status' => 'active',
            'summary' => 'Activated against the accepted contract-risk review.',
        ]);

        return [$engagement->refresh(), $manager, $engagement->businessOwner()->firstOrFail()];
    }
}
