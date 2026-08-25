<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeMessage;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseIntakeMessageFactory extends Factory
{
    protected $model = ComplianceCaseIntakeMessage::class;

    public function definition(): array
    {
        return ['compliance_case_intake_id' => ComplianceCaseIntake::factory(), 'version' => 1, 'audience' => ComplianceCaseIntakeAudience::Reporter, 'message' => fake()->sentence(), 'actor_id' => fn (array $a) => ComplianceCaseIntake::query()->findOrFail($a['compliance_case_intake_id'])->submitted_by, 'actor_snapshot' => [], 'intake_snapshot' => [], 'disposition_snapshot' => null, 'recorded_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseIntakeMessage $message): void {
            $message->loadMissing(['intake', 'actor']);
            $manager = app(ComplianceCaseIntakeCorrespondenceManager::class);
            $message->actor_snapshot = $message->actor->only(['id', 'name', 'email']);
            $message->intake_snapshot = $manager->intakeSnapshot($message->intake);
            $message->disposition_snapshot = $manager->dispositionSnapshot($message->intake);
            $message->fingerprint = hash('sha256', CanonicalJson::encode($manager->payload($message)));
        });
    }
}
