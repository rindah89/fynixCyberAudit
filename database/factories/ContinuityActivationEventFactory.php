<?php

namespace Database\Factories;

use App\Enums\ContinuityActivationStatus;
use App\Models\ContinuityActivation;
use App\Models\ContinuityActivationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContinuityActivationEventFactory extends Factory
{
    protected $model = ContinuityActivationEvent::class;

    public function definition(): array
    {
        $activation = ContinuityActivation::factory()->create();
        $snapshot = $activation->toArray();
        unset($snapshot['created_at'], $snapshot['updated_at']);
        $at = now();
        $payload = ['continuity_activation_id' => $activation->id, 'version' => 1, 'from_status' => null, 'to_status' => ContinuityActivationStatus::Activated->value, 'summary' => 'Approved recovery plan activated for the reported disruption.', 'activation_snapshot' => $snapshot, 'recorded_by' => $activation->activated_by, 'recorded_at' => $at->toIso8601String()];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }
}
