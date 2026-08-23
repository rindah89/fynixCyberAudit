<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRiskAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorRiskAssessmentFactory extends Factory
{
    protected $model = VendorRiskAssessment::class;

    public function definition(): array
    {
        return ['vendor_id' => Vendor::factory(), 'assessor_id' => User::factory(), 'version' => 1, 'likelihood' => 4, 'impact' => 5, 'inherent_score' => 20, 'residual_likelihood' => 2, 'residual_impact' => 3, 'residual_score' => 6, 'risk_categories' => ['cybersecurity'], 'assessment_summary' => fake()->paragraph(), 'treatment_summary' => fake()->paragraph(), 'assessed_at' => now()];
    }
}
