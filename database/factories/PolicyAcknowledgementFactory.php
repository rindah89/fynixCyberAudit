<?php

namespace Database\Factories;

use App\Models\PolicyAcknowledgement;
use App\Models\PolicyAcknowledgementAssignment;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyAcknowledgementFactory extends Factory
{
    protected $model = PolicyAcknowledgement::class;

    public function definition(): array
    {
        return [
            'policy_acknowledgement_assignment_id' => PolicyAcknowledgementAssignment::factory(),
            'acknowledged_by' => fn (array $attributes): int => PolicyAcknowledgementAssignment::query()->findOrFail($attributes['policy_acknowledgement_assignment_id'])->user_id,
            'statement' => PolicyAcknowledgementManager::STATEMENT,
            'campaign_snapshot' => function (array $attributes): array {
                $campaign = PolicyAcknowledgementAssignment::query()->findOrFail($attributes['policy_acknowledgement_assignment_id'])->campaign;

                return $campaign->only(['id', 'version', 'title', 'instructions', 'due_at', 'launched_by', 'launched_at']);
            },
            'policy_snapshot' => fn (array $attributes): array => PolicyAcknowledgementAssignment::query()
                ->findOrFail($attributes['policy_acknowledgement_assignment_id'])->campaign->policy_snapshot,
            'policy_fingerprint' => fn (array $attributes): string => PolicyAcknowledgementAssignment::query()
                ->findOrFail($attributes['policy_acknowledgement_assignment_id'])->campaign->policy_fingerprint,
            'acknowledged_at' => now(),
        ];
    }
}
