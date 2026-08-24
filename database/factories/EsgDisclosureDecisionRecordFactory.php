<?php

namespace Database\Factories;

use App\Enums\EsgDisclosureDecision;
use App\Models\EsgDisclosure;
use App\Models\EsgDisclosureDecisionRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgDisclosureDecisionRecord> */
class EsgDisclosureDecisionRecordFactory extends Factory
{
    public function definition(): array
    {
        $disclosure = EsgDisclosure::factory()->create();
        $decider = User::factory()->create();
        $decidedAt = now()->startOfSecond();
        $payload = [
            'esg_disclosure_id' => $disclosure->id, 'version' => 1,
            'disclosure_snapshot' => self::disclosureSnapshot($disclosure),
            'decision' => EsgDisclosureDecision::Approved->value,
            'rationale' => 'Factory independent disclosure decision.',
            'decided_by' => $decider->id, 'decided_at' => $decidedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    private static function disclosureSnapshot(EsgDisclosure $disclosure): array
    {
        $disclosure->load(['preparer:id,name,email', 'validations.validator:id,name,email']);
        $validationSnapshot = $disclosure->validations->map(function ($validation): array {
            $snapshot = $validation->only(['id', 'esg_kpi_observation_id', 'version', 'observation_snapshot', 'completeness_assessment', 'accuracy_assessment', 'consistency_assessment', 'evidence_reference', 'outcome', 'summary', 'validated_at', 'fingerprint']) + ['validator' => $validation->validator?->only(['id', 'name', 'email'])];

            return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
        })->values()->all();
        $snapshot = $disclosure->only(['id', 'disclosure_key', 'code', 'version', 'title', 'reporting_period_start', 'reporting_period_end', 'framework_references', 'narrative', 'prepared_at', 'fingerprint']) + ['validation_snapshot' => $validationSnapshot, 'preparer' => $disclosure->preparer?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
