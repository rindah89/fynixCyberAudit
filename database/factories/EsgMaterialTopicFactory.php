<?php

namespace Database\Factories;

use App\Enums\EsgPillar;
use App\Enums\EsgTopicStatus;
use App\Models\EsgMaterialTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgMaterialTopic> */
class EsgMaterialTopicFactory extends Factory
{
    public function definition(): array
    {
        $governedAt = now()->startOfSecond();

        return [
            'code' => 'ESG-'.$governedAt->format('Y').'-'.$this->faker->unique()->numerify('######'),
            'name' => $this->faker->unique()->sentence(3),
            'pillar' => EsgPillar::Environmental,
            'status' => EsgTopicStatus::Draft,
            'owner_id' => User::factory(),
            'description' => 'Factory material topic description.',
            'impact_context' => 'Factory outward impact context.',
            'risk_context' => 'Factory financial risk context.',
            'opportunity_context' => 'Factory opportunity context.',
            'stakeholder_groups' => ['Employees', 'Customers'],
            'framework_references' => ['GRI 3'],
            'organizational_boundary' => 'Consolidated operations.',
            'source_reference' => 'FACTORY-ESG-SOURCE',
            'next_review_at' => today()->addYear(),
            'governed_at' => $governedAt,
        ];
    }
}
