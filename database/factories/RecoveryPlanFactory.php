<?php

namespace Database\Factories;

use App\Models\BusinessService;
use App\Models\RecoveryPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecoveryPlanFactory extends Factory
{
    protected $model = RecoveryPlan::class;

    public function definition(): array
    {
        return [
            'business_service_id' => BusinessService::factory(), 'owner_id' => User::factory(), 'version' => 1,
            'title' => fake()->sentence(3), 'strategy' => fake()->paragraph(), 'activation_criteria' => fake()->sentence(),
            'recovery_procedure' => fake()->paragraph(), 'communication_plan' => fake()->paragraph(),
            'status' => 'draft', 'review_due_at' => now()->addYear(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_by' => User::factory(), 'approved_at' => now()]);
    }
}
