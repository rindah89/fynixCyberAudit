<?php

namespace Database\Factories;

use App\Models\Control;
use App\Models\ControlTestDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ControlTestDefinitionFactory extends Factory
{
    protected $model = ControlTestDefinition::class;

    public function definition(): array
    {
        return [
            'control_id' => Control::factory(),
            'owner_id' => User::factory(),
            'code' => fake()->unique()->bothify('CCT-??##'),
            'name' => fake()->sentence(3),
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '10',
            'frequency' => 'monthly',
            'next_run_at' => now(),
            'is_active' => true,
        ];
    }
}
