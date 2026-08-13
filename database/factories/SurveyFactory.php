<?php

namespace Database\Factories;

use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Models\Survey;
use App\Models\SurveyTemplate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    public function definition(): array
    {
        return [
            'survey_template_id' => SurveyTemplate::factory(),
            'title' => fake()->sentence(3),
            'status' => SurveyStatus::SENT,
            'type' => SurveyType::VENDOR_ASSESSMENT,
            'respondent_email' => fake()->unique()->safeEmail(),
            'respondent_name' => fake()->name(),
            'vendor_id' => Vendor::factory(),
            'created_by_id' => User::factory(),
        ];
    }
}
