<?php

namespace Database\Factories;

use App\Models\RegulatoryRequirement;
use App\Models\RegulatorySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegulatoryRequirementFactory extends Factory
{
    protected $model = RegulatoryRequirement::class;

    public function definition(): array
    {
        return [
            'regulatory_source_id' => RegulatorySource::factory(), 'code' => fake()->unique()->bothify('REQ-####'),
            'owner_id' => User::factory(), 'created_by' => User::factory(),
        ];
    }
}
