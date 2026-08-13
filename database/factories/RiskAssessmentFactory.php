<?php

namespace Database\Factories;

use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAssessment>
 */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'owner_id' => User::factory(),
            'mode' => 'manual',
            'status' => 'draft',
        ];
    }
}
