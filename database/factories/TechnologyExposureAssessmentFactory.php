<?php

namespace Database\Factories;

use App\Enums\TechnologyExposureState;
use App\Enums\TechnologyExposureType;
use App\Models\Asset;
use App\Models\Risk;
use App\Models\TechnologyExposureAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TechnologyExposureAssessmentFactory extends Factory
{
    protected $model = TechnologyExposureAssessment::class;

    public function definition(): array
    {
        return [
            'risk_id' => Risk::factory(), 'version' => 1, 'asset_id_snapshot' => Asset::factory()->state(['asset_tag' => fake()->unique()->bothify('AST-#####'), 'name' => fake()->words(3, true)]), 'assessed_by' => User::factory(),
            'exposure_type' => TechnologyExposureType::Vulnerability, 'title' => fake()->sentence(4), 'threat_scenario' => fake()->sentence(),
            'vulnerability_description' => fake()->sentence(), 'inherent_likelihood' => 4, 'inherent_impact' => 4, 'inherent_score' => 16,
            'residual_likelihood' => 2, 'residual_impact' => 3, 'residual_score' => 6, 'appetite_threshold_snapshot' => 8,
            'state' => TechnologyExposureState::WithinAppetite, 'recommended_response' => fake()->sentence(), 'review_due_at' => now()->addQuarter(),
            'asset_snapshot' => function (array $attributes): array {
                $asset = Asset::query()->findOrFail($attributes['asset_id_snapshot']);

                return $asset->only(['id', 'asset_tag', 'name', 'is_active', 'asset_criticality_id', 'data_classification_id', 'asset_exposure_id']);
            },
            'governance_snapshot' => function (array $attributes): array {
                $risk = Risk::query()->findOrFail($attributes['risk_id']);

                return ['risk' => $risk->only(['id', 'code', 'name', 'domain']), 'profile' => [], 'business_service' => null, 'assets' => [], 'implementations' => []];
            },
            'governance_fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($attributes['governance_snapshot'], JSON_THROW_ON_ERROR)), 'assessed_at' => now(),
        ];
    }
}
