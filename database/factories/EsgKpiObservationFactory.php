<?php

namespace Database\Factories;

use App\Enums\EsgGoalStatus;
use App\Enums\EsgKpiStatus;
use App\Models\EsgKpi;
use App\Models\EsgKpiObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgKpiObservation> */
class EsgKpiObservationFactory extends Factory
{
    public function definition(): array
    {
        $kpi = EsgKpi::factory()->create();
        $observedAt = now()->startOfSecond();
        $payload = [
            'esg_kpi_id' => $kpi->id, 'version' => 1, 'kpi_snapshot' => self::kpiSnapshot($kpi),
            'observed_value' => '85.000000', 'status' => EsgKpiStatus::TargetNotMet->value,
            'reason' => 'Observed 85.000000 index points; target 70.000000; direction decrease; derived status target_not_met.',
            'notes' => 'Factory ESG performance observation.', 'source_reference' => 'FACTORY-ESG-DATA',
            'observed_by' => $kpi->owner_id, 'observed_at' => $observedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    private static function kpiSnapshot(EsgKpi $kpi): array
    {
        $kpi->load(['owner:id,name,email', 'creator:id,name,email', 'goal.owner:id,name,email', 'goal.creator:id,name,email']);
        $goal = $kpi->goal;
        $goalSnapshot = $goal->only(['id', 'esg_material_topic_id', 'code', 'title', 'description', 'status', 'baseline_date', 'target_date', 'topic_snapshot', 'assessment_snapshot', 'governed_at', 'fingerprint']) + ['owner' => $goal->owner?->only(['id', 'name', 'email']), 'creator' => $goal->creator?->only(['id', 'name', 'email'])];
        $snapshot = $kpi->only(['id', 'esg_goal_id', 'code', 'name', 'description', 'unit', 'direction', 'baseline_value', 'target_value', 'measurement_method', 'source_reference', 'frequency_days', 'is_active', 'governed_at', 'fingerprint']) + ['owner' => $kpi->owner?->only(['id', 'name', 'email']), 'creator' => $kpi->creator?->only(['id', 'name', 'email']), 'goal' => $goalSnapshot];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (EsgKpiObservation $observation): void {
            $observation->kpi->update([
                'last_observed_at' => $observation->observed_at,
                'last_status' => $observation->status,
                'next_due_at' => $observation->observed_at->copy()->addDays($observation->kpi->frequency_days),
            ]);
            $observation->kpi->goal->update(['status' => EsgGoalStatus::AtRisk]);
        });
    }
}
