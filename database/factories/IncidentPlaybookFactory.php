<?php

namespace Database\Factories;

use App\Models\IncidentPlaybook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentPlaybook>
 */
class IncidentPlaybookFactory extends Factory
{
    protected $model = IncidentPlaybook::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'incident_type' => $this->faker->randomElement(['Malware', 'Phishing', 'Theft', 'Denial of Service']),
            'description' => $this->faker->sentence(),
        ];
    }
}
