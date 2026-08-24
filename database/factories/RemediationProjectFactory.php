<?php

namespace Database\Factories;

use App\Models\RemediationProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemediationProjectFactory extends Factory
{
    protected $model = RemediationProject::class;

    public function definition(): array
    {
        return [
            'code' => 'RP-'.$this->faker->unique()->numerify('######'),
            'name' => $this->faker->sentence(4),
            'status' => 'active',
            'owner_id' => User::factory(),
            'start_date' => now()->toDateString(),
            'due_date' => now()->addMonths(3)->toDateString(),
        ];
    }
}
