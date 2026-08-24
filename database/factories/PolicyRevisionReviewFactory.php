<?php

namespace Database\Factories;

use App\Enums\PolicyRevisionDecision;
use App\Enums\PolicyRevisionStatus;
use App\Models\PolicyRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyRevisionReviewFactory extends Factory
{
    public function definition(): array
    {
        $revision = PolicyRevision::factory()->create();
        $reviewer = User::factory()->create();
        $reviewedAt = now()->startOfSecond();
        $snapshot = [
            'id' => $revision->id, 'policy_id' => $revision->policy_id, 'version' => $revision->version,
            'status' => $revision->status->value, 'change_summary' => $revision->change_summary,
            'proposed_effective_date' => $revision->proposed_effective_date->toDateString(),
            'policy_snapshot' => $revision->policy_snapshot, 'submitted_by' => $revision->submitted_by,
            'submitted_at' => $revision->submitted_at->toISOString(), 'fingerprint' => $revision->fingerprint,
        ];
        $payload = [
            'policy_revision_id' => $revision->id, 'decision' => PolicyRevisionDecision::Approved->value,
            'review_summary' => 'Independent approval.', 'revision_snapshot' => $snapshot,
            'reviewed_by' => $reviewer->id, 'reviewed_at' => $reviewedAt->toISOString(),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($review): void {
            $review->revision->update(['status' => PolicyRevisionStatus::Approved]);
        });
    }
}
