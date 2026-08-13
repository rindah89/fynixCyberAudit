<?php

namespace Database\Factories;

use App\Models\RiskAssessment;
use App\Models\RiskAssessmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAssessmentItem>
 */
class RiskAssessmentItemFactory extends Factory
{
    protected $model = RiskAssessmentItem::class;

    public function definition(): array
    {
        $likelihood = $this->faker->numberBetween(2, 5);
        $impact = $this->faker->numberBetween(2, 5);

        return [
            'risk_assessment_id' => RiskAssessment::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_risk' => $likelihood * $impact,
        ];
    }
}
