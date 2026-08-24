<?php

namespace Database\Factories;

use App\Enums\EsgDataValidationOutcome;
use App\Models\EsgDataValidation;
use App\Models\EsgKpiObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgDataValidation> */
class EsgDataValidationFactory extends Factory
{
    public function definition(): array
    {
        $observation = EsgKpiObservation::factory()->create();
        $validator = User::factory()->create();
        $validatedAt = now()->startOfSecond();
        $payload = [
            'esg_kpi_observation_id' => $observation->id, 'version' => 1,
            'observation_snapshot' => self::observationSnapshot($observation),
            'completeness_assessment' => 'Factory completeness assessment.',
            'accuracy_assessment' => 'Factory accuracy assessment.',
            'consistency_assessment' => 'Factory consistency assessment.',
            'evidence_reference' => 'FACTORY-ESG-VALIDATION', 'outcome' => EsgDataValidationOutcome::Validated->value,
            'summary' => 'Factory independent data-validation judgment.', 'validated_by' => $validator->id,
            'validated_at' => $validatedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => self::fingerprint($payload)];
    }

    private static function observationSnapshot(EsgKpiObservation $observation): array
    {
        $observation->load('observer:id,name,email');
        $snapshot = $observation->only(['id', 'esg_kpi_id', 'version', 'kpi_snapshot', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'observed_at', 'fingerprint']) + ['observer' => $observation->observer?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
