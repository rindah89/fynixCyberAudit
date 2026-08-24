<?php

namespace Database\Factories;

use App\Enums\AuditableEntityCriticality;
use App\Enums\AuditableEntityStatus;
use App\Enums\AuditableEntityType;
use App\Enums\RiskReviewFrequency;
use App\Models\AuditableEntity;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditableEntityFactory extends Factory
{
    protected $model = AuditableEntity::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('AE-####'), 'name' => fake()->company(), 'description' => fake()->sentence(),
            'entity_type' => AuditableEntityType::BusinessProcess, 'owner_id' => User::factory(),
            'criticality' => AuditableEntityCriticality::High, 'status' => AuditableEntityStatus::Active,
            'assessment_frequency' => RiskReviewFrequency::Annual, 'next_assessment_at' => today()->addYear(),
            'created_by' => User::factory(), 'updated_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AuditableEntity $entity): void {
            if ($entity->risks()->doesntExist()) {
                $entity->risks()->attach(Risk::factory()->create());
            }
            if ($entity->controls()->doesntExist()) {
                $entity->controls()->attach(Control::factory()->create());
            }
        });
    }
}
