<?php

namespace Database\Factories;

use App\Models\AiGovernanceDecision;
use App\Models\AiRiskAssessment;
use App\Models\AiUseCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiGovernanceDecisionFactory extends Factory
{
    protected $model = AiGovernanceDecision::class;

    public function definition(): array
    {
        return [
            'ai_use_case_id' => AiUseCase::factory(), 'ai_risk_assessment_id' => AiRiskAssessment::factory(),
            'decided_by' => User::factory(), 'decision' => 'changes_required', 'rationale' => fake()->paragraph(),
            'assessment_version' => 1, 'residual_score' => 9, 'controls_count' => 0, 'risks_count' => 0,
            'control_ids' => [], 'risk_ids' => [], 'system_snapshot' => [], 'use_case_snapshot' => [],
            'governance_fingerprint' => hash('sha256', 'factory-placeholder'),
            'decided_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['decision' => 'approved', 'expires_at' => now()->addYear(), 'decided_at' => now()]);
    }
}
