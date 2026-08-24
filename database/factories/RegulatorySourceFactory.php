<?php

namespace Database\Factories;

use App\Enums\RegulatorySourceStatus;
use App\Models\RegulatorySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegulatorySourceFactory extends Factory
{
    protected $model = RegulatorySource::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('REG-####'), 'title' => fake()->sentence(4),
            'authority' => fake()->company(), 'jurisdiction' => fake()->countryCode(),
            'reference_url' => fake()->url(), 'owner_id' => User::factory(), 'status' => RegulatorySourceStatus::Active,
            'created_by' => User::factory(), 'updated_by' => User::factory(),
        ];
    }
}
