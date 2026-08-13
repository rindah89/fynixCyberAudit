<?php

namespace Database\Factories;

use App\Enums\SurveyTemplateStatus;
use App\Enums\SurveyType;
use App\Models\SurveyTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyTemplate>
 */
class SurveyTemplateFactory extends Factory
{
    protected $model = SurveyTemplate::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => SurveyTemplateStatus::ACTIVE,
            'type' => SurveyType::VENDOR_ASSESSMENT,
            'created_by_id' => User::factory(),
        ];
    }
}
