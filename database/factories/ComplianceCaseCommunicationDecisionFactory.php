<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseCommunicationManager;
use App\Enums\ComplianceCaseCommunicationDecisionType;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseCommunicationDecision;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseCommunicationDecision> */
class ComplianceCaseCommunicationDecisionFactory extends Factory
{
    protected $model = ComplianceCaseCommunicationDecision::class;

    public function definition(): array
    {
        $actor = User::factory()->create();
        $actor->assignRole('Security Admin');
        $case = ComplianceCase::factory()->create(['opened_by' => $actor->id]);
        $decidedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => $case->id, 'version' => 1, 'audience' => 'internal',
            'purpose' => 'Factory governed communication decision.',
            'decision' => ComplianceCaseCommunicationDecisionType::Prepared,
            'deadline_at' => $decidedAt->copy()->addDay(), 'external_reference' => null,
            'decided_by' => $actor->id, 'decider_snapshot' => $actor->only(['id', 'name', 'email']),
            'case_snapshot' => ['id' => $case->id, 'number' => $case->number, 'status' => $case->status->value],
            'decided_at' => $decidedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseCommunicationDecision $decision): void {
            $decision->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseCommunicationManager::class)->payload($decision),
            ));
        });
    }
}
