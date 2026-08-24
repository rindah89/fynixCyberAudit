<?php

namespace Database\Factories;

use App\Enums\PolicyExceptionDecisionType;
use App\Enums\PolicyExceptionStatus;
use App\Models\PolicyException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyExceptionDecisionFactory extends Factory
{
    public function definition(): array
    {
        $exception = PolicyException::factory()->governed()->create();
        $decider = User::factory()->create();
        $decidedAt = now()->startOfSecond();
        $snapshot = [
            'id' => $exception->id, 'policy_id' => $exception->policy_id, 'status' => $exception->status->value,
            'name' => $exception->name, 'description' => $exception->description, 'justification' => $exception->justification,
            'risk_assessment' => $exception->risk_assessment, 'compensating_controls' => $exception->compensating_controls,
            'effective_date' => $exception->effective_date?->toDateString(), 'expiration_date' => $exception->expiration_date?->toDateString(),
            'requested_by' => $exception->requested_by, 'requested_date' => $exception->requested_date?->toDateString(),
            'submitted_at' => $exception->submitted_at?->toISOString(), 'governance_snapshot' => $exception->governance_snapshot,
            'governance_fingerprint' => $exception->governance_fingerprint,
        ];
        $payload = [
            'policy_exception_id' => $exception->id, 'version' => 1, 'decision' => PolicyExceptionDecisionType::Approved->value,
            'decision_summary' => 'Independent governed approval.', 'exception_snapshot' => $snapshot,
            'decided_by' => $decider->id, 'decided_at' => $decidedAt->toISOString(),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($decision): void {
            $decision->exception->update(['status' => PolicyExceptionStatus::Approved, 'approved_by' => $decision->decided_by]);
        });
    }
}
