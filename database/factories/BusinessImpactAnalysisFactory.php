<?php

namespace Database\Factories;

use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessImpactAnalysisFactory extends Factory
{
    protected $model = BusinessImpactAnalysis::class;

    public function definition(): array
    {
        return [
            'business_service_id' => BusinessService::factory(), 'analyst_id' => User::factory(), 'version' => 1,
            'maximum_tolerable_downtime_minutes' => 240, 'recovery_time_objective_minutes' => 120,
            'recovery_point_objective_minutes' => 15, 'operational_impact' => 'high', 'rationale' => fake()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['approved_by' => User::factory(), 'approved_at' => now()]);
    }
}
