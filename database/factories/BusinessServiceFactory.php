<?php

namespace Database\Factories;

use App\Models\BusinessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessServiceFactory extends Factory
{
    protected $model = BusinessService::class;

    public function definition(): array
    {
        return ['owner_id' => User::factory(), 'code' => fake()->unique()->bothify('SVC-??##'), 'name' => fake()->words(3, true), 'criticality' => 'high', 'status' => 'active'];
    }
}
