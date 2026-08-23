<?php

namespace Database\Factories;

use App\Models\AiRiskAssessment;
use App\Models\AiUseCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiRiskAssessmentFactory extends Factory
{
    protected $model = AiRiskAssessment::class;

    public function definition(): array
    {
        return [
            'ai_use_case_id' => AiUseCase::factory(), 'assessor_id' => User::factory(), 'version' => 1,
            'likelihood' => 3, 'impact' => 4, 'inherent_score' => 12, 'residual_likelihood' => 3,
            'residual_impact' => 3, 'residual_score' => 9, 'risk_categories' => ['security'],
            'assessment_summary' => fake()->paragraph(), 'mitigation_summary' => fake()->paragraph(), 'assessed_at' => now(),
        ];
    }
}
