<?php

namespace Database\Factories;

use App\Enums\EsgKpiDirection;
use App\Models\EsgGoal;
use App\Models\EsgKpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgKpi> */
class EsgKpiFactory extends Factory
{
    public function definition(): array
    {
        $goal = EsgGoal::factory()->create();
        $governedAt = now()->startOfSecond();
        $payload = [
            'esg_goal_id' => $goal->id, 'code' => $goal->code.'-K001', 'name' => 'Factory ESG KPI',
            'description' => 'Factory governed ESG performance indicator.', 'owner_id' => $goal->owner_id,
            'unit' => 'index points', 'direction' => EsgKpiDirection::Decrease->value,
            'baseline_value' => '100.000000', 'target_value' => '70.000000',
            'measurement_method' => 'Factory deliberate measurement method.', 'source_reference' => 'FACTORY-ESG-KPI',
            'frequency_days' => 90, 'goal_snapshot' => self::goalSnapshot($goal),
            'created_by' => $goal->created_by, 'governed_at' => $governedAt->toIso8601String(),
        ];

        return $payload + ['next_due_at' => now()->addDays(90), 'is_active' => true, 'fingerprint' => self::fingerprint($payload)];
    }

    private static function goalSnapshot(EsgGoal $goal): array
    {
        $goal->load(['owner:id,name,email', 'creator:id,name,email']);

        return json_decode(json_encode($goal->only(['id', 'esg_material_topic_id', 'code', 'title', 'description', 'status', 'baseline_date', 'target_date', 'topic_snapshot', 'assessment_snapshot', 'governed_at', 'fingerprint']) + ['owner' => $goal->owner?->only(['id', 'name', 'email']), 'creator' => $goal->creator?->only(['id', 'name', 'email'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
