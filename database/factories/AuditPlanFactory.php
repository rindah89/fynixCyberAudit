<?php

namespace Database\Factories;

use App\Enums\AuditPlanStatus;
use App\Models\AuditPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditPlanFactory extends Factory
{
    protected $model = AuditPlan::class;

    public function definition(): array
    {
        return ['plan_year' => (int) today()->year, 'name' => fake()->unique()->words(3, true), 'objective' => fake()->paragraph(), 'manager_id' => User::factory(), 'status' => AuditPlanStatus::Draft, 'created_by' => User::factory()];
    }
}
