<?php

namespace Database\Factories;

use App\Models\Policy;
use App\Models\PolicyObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyObligationFactory extends Factory
{
    protected $model = PolicyObligation::class;

    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'owner_id' => User::factory(),
            'code' => 'POL-'.fake()->unique()->numerify('#####'),
            'title' => fake()->sentence(4),
            'frequency' => 'annual',
            'next_due_at' => now()->addYear(),
            'is_active' => true,
        ];
    }
}
