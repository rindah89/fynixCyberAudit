<?php

namespace Database\Factories;

use App\Models\EsgDataValidation;
use App\Models\EsgDisclosure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgDisclosure> */
class EsgDisclosureFactory extends Factory
{
    public function definition(): array
    {
        $validation = EsgDataValidation::factory()->create();
        $preparer = User::factory()->create();
        $preparedAt = now()->startOfSecond();
        $key = 'FACTORY_ESG_'.$this->faker->unique()->numerify('######');
        $payload = [
            'disclosure_key' => $key, 'code' => $key.'-V001', 'version' => 1,
            'title' => 'Factory ESG disclosure', 'reporting_period_start' => today()->subYear()->toDateString(),
            'reporting_period_end' => today()->toDateString(), 'framework_references' => ['GRI 305'],
            'narrative' => 'Factory disclosure narrative based on selected validated observations.',
            'validation_snapshot' => [self::validationSnapshot($validation)],
            'prepared_by' => $preparer->id, 'prepared_at' => $preparedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => self::fingerprint($payload), '_validation_id' => $validation->id];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EsgDisclosure $disclosure): void {
            unset($disclosure->_validation_id);
        })->afterCreating(function (EsgDisclosure $disclosure): void {
            $validationId = data_get($disclosure->validation_snapshot, '0.id');
            if ($validationId) {
                $disclosure->validations()->attach($validationId);
            }
        });
    }

    private static function validationSnapshot(EsgDataValidation $validation): array
    {
        $validation->load('validator:id,name,email');
        $snapshot = $validation->only(['id', 'esg_kpi_observation_id', 'version', 'observation_snapshot', 'completeness_assessment', 'accuracy_assessment', 'consistency_assessment', 'evidence_reference', 'outcome', 'summary', 'validated_at', 'fingerprint']) + ['validator' => $validation->validator?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
