<?php

namespace Database\Factories;

use App\Models\AiSystem;
use App\Models\AiUseCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiUseCaseFactory extends Factory
{
    protected $model = AiUseCase::class;

    public function definition(): array
    {
        return [
            'ai_system_id' => AiSystem::factory(), 'owner_id' => User::factory(), 'name' => fake()->sentence(3),
            'purpose' => fake()->paragraph(), 'decision_impact' => 'medium', 'affected_population' => 'Internal users',
            'uses_personal_data' => false, 'uses_sensitive_data' => false, 'automated_decision' => false,
        ];
    }
}
