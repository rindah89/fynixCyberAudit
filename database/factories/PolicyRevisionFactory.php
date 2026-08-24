<?php

namespace Database\Factories;

use App\Enums\PolicyRevisionStatus;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyRevisionFactory extends Factory
{
    public function definition(): array
    {
        $policy = Policy::factory()->create(['effective_date' => today()]);
        $submitter = User::factory()->create();
        $effectiveDate = $policy->effective_date->toDateString();
        $snapshot = $policy->only([
            'id', 'code', 'name', 'document_type', 'policy_scope', 'purpose', 'body', 'document_path',
            'scope_id', 'department_id', 'status_id', 'owner_id', 'retired_date', 'revision_history',
        ]) + ['effective_date' => $effectiveDate, 'risks' => [], 'controls' => [], 'implementations' => []];
        $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
        $payload = [
            'policy_id' => $policy->id, 'version' => 1, 'change_summary' => 'Governed policy revision.',
            'proposed_effective_date' => $effectiveDate, 'policy_snapshot' => $snapshot,
        ];

        return $payload + [
            'status' => PolicyRevisionStatus::PendingReview,
            'submitted_by' => $submitter->id, 'submitted_at' => now()->startOfSecond(),
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }
}
