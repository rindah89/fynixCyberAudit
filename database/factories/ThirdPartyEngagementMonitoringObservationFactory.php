<?php

namespace Database\Factories;

use App\Enums\RiskIndicatorStatus;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementMonitoringObservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementMonitoringObservationFactory extends Factory
{
    protected $model = ThirdPartyEngagementMonitoringObservation::class;

    public function definition(): array
    {
        return ['third_party_engagement_monitoring_indicator_id' => ThirdPartyEngagementMonitoringIndicator::factory(), 'version' => 1,
            'observed_value' => '99.400000', 'status' => RiskIndicatorStatus::Critical, 'reason' => '', 'notes' => 'Factory observation.',
            'source_reference' => 'FACTORY-REPORT', 'indicator_snapshot' => [], 'engagement_snapshot' => [], 'contract_review_snapshot' => [], 'risk_approval_snapshot' => [],
            'observed_by' => fn (array $attributes): int => ThirdPartyEngagementMonitoringIndicator::query()->findOrFail($attributes['third_party_engagement_monitoring_indicator_id'])->owner_id,
            'observed_at' => now()->startOfSecond(), 'recorded_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementMonitoringObservation $observation): void {
            $indicator = ThirdPartyEngagementMonitoringIndicator::query()->findOrFail($observation->third_party_engagement_monitoring_indicator_id);
            $reason = "Observed {$observation->observed_value} {$indicator->unit}; derived {$observation->status->value} against {$indicator->direction->value} warning {$indicator->warning_threshold} and critical {$indicator->critical_threshold}.";
            $payload = ['third_party_engagement_monitoring_indicator_id' => $indicator->id, 'version' => $observation->version, 'observed_value' => $observation->observed_value,
                'status' => $observation->status->value, 'reason' => $reason, 'notes' => $observation->notes, 'source_reference' => $observation->source_reference,
                'indicator_snapshot' => Arr::only($indicator->toArray(), ['id', 'third_party_engagement_id', 'version', 'code', 'name', 'description', 'category', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency_days', 'owner_id', 'measurement_method', 'fingerprint']),
                'engagement_snapshot' => $indicator->engagement_snapshot, 'contract_review_snapshot' => $indicator->contract_review_snapshot, 'risk_approval_snapshot' => $indicator->risk_approval_snapshot,
                'observed_by' => $observation->observed_by, 'observed_at' => $observation->observed_at->toIso8601String(), 'recorded_at' => $observation->recorded_at->toIso8601String()];
            $observation->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
