<?php

namespace Database\Factories;

use App\Models\AiSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiSystemFactory extends Factory
{
    protected $model = AiSystem::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(), 'code' => fake()->unique()->bothify('AI-??##'), 'name' => fake()->words(3, true),
            'provider_name' => 'Internal', 'model_name' => 'model-v1', 'deployment_type' => 'internal',
            'lifecycle_status' => 'pilot', 'criticality' => 'medium', 'intended_purpose' => fake()->sentence(),
            'human_oversight' => fake()->sentence(), 'data_categories' => [], 'next_review_at' => now()->addMonths(6),
        ];
    }
}
