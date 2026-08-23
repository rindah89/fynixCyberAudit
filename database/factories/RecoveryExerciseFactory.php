<?php

namespace Database\Factories;

use App\Models\RecoveryExercise;
use App\Models\RecoveryPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecoveryExerciseFactory extends Factory
{
    protected $model = RecoveryExercise::class;

    public function definition(): array
    {
        return ['recovery_plan_id' => RecoveryPlan::factory(), 'facilitator_id' => User::factory(), 'scenario' => fake()->sentence(), 'scheduled_at' => now()];
    }
}
