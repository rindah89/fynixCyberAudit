<?php

namespace Database\Factories;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorStatus;
use App\Models\RiskIndicator;
use App\Models\RiskIndicatorObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskIndicatorObservationFactory extends Factory
{
    protected $model = RiskIndicatorObservation::class;

    public function definition(): array
    {
        return ['risk_indicator_id' => RiskIndicator::factory(), 'observed_by' => User::factory(), 'observed_value' => '3.000000', 'unit_snapshot' => 'percent', 'direction_snapshot' => RiskIndicatorDirection::HigherIsWorse, 'warning_threshold_snapshot' => '5.000000', 'critical_threshold_snapshot' => '10.000000', 'status' => RiskIndicatorStatus::Normal, 'reason' => 'Derived from snapshotted thresholds.', 'observed_at' => now()];
    }
}
