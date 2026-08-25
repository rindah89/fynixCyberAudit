<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseIntakeMessageAcknowledgementManager;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntakeMessage;
use App\Models\ComplianceCaseIntakeMessageAcknowledgement;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseIntakeMessageAcknowledgementFactory extends Factory
{
    protected $model = ComplianceCaseIntakeMessageAcknowledgement::class;

    public function definition(): array
    {
        return ['compliance_case_intake_message_id' => ComplianceCaseIntakeMessage::factory()->state(['audience' => ComplianceCaseIntakeAudience::Reporter, 'actor_id' => User::factory()]),
            'recipient_id' => fn (array $attributes) => ComplianceCaseIntakeMessage::query()->findOrFail($attributes['compliance_case_intake_message_id'])->intake->submitted_by,
            'recipient_snapshot' => [], 'message_snapshot' => [], 'acknowledged_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseIntakeMessageAcknowledgement $acknowledgement): void {
            $acknowledgement->loadMissing(['message', 'recipient']);
            $manager = app(ComplianceCaseIntakeMessageAcknowledgementManager::class);
            $acknowledgement->recipient_snapshot = $acknowledgement->recipient->only(['id', 'name', 'email']);
            $acknowledgement->message_snapshot = $manager->messageSnapshot($acknowledgement->message);
            $acknowledgement->fingerprint = hash('sha256', CanonicalJson::encode($manager->payload($acknowledgement)));
        });
    }
}
