<?php

namespace Database\Factories;

use App\Enums\PrivacyActivityStatus;
use App\Models\PrivacyProcessingActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrivacyProcessingActivityFactory extends Factory
{
    protected $model = PrivacyProcessingActivity::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['number' => 'PA-FAC-'.Str::upper(Str::random(10)), 'name' => fake()->sentence(4), 'status' => PrivacyActivityStatus::Draft, 'owner_id' => User::factory(), 'purpose' => fake()->paragraph(), 'lawful_basis' => 'Legitimate interests', 'data_subject_categories' => ['Customers'], 'personal_data_categories' => ['Contact details'], 'special_category_data' => false, 'recipient_categories' => ['Authorized staff'], 'systems_and_vendors' => ['CRM'], 'processing_locations' => ['Cameroon'], 'cross_border_transfer' => false, 'transfer_safeguards' => null, 'retention_period' => 'Seven years', 'security_measures' => 'Role-based access and encryption.', 'source_reference' => null, 'next_review_at' => today()->addYear(), 'governed_at' => $at];
    }
}
