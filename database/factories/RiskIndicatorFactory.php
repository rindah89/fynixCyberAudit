<?php

namespace Database\Factories;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorFrequency;
use App\Models\Risk;
use App\Models\RiskIndicator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskIndicatorFactory extends Factory
{
    protected $model = RiskIndicator::class;

    public function definition(): array
    {
        return ['risk_id' => Risk::factory(), 'owner_id' => User::factory(), 'code' => fake()->unique()->bothify('KRI-###'), 'name' => fake()->sentence(3), 'description' => fake()->sentence(), 'unit' => 'percent', 'direction' => RiskIndicatorDirection::HigherIsWorse, 'warning_threshold' => '5.000000', 'critical_threshold' => '10.000000', 'frequency' => RiskIndicatorFrequency::Monthly, 'next_due_at' => now()->addMonth(), 'is_active' => true];
    }
}
