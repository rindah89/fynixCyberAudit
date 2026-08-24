<?php

namespace Database\Factories;

use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyEngagementEventFactory extends Factory
{
    protected $model = ThirdPartyEngagementEvent::class;

    public function definition(): array
    {
        return [
            'third_party_engagement_id' => ThirdPartyEngagement::factory(),
            'version' => 1,
            'from_status' => null,
            'to_status' => ThirdPartyEngagementStatus::Proposed,
            'summary' => 'Third-party engagement proposed for governed due diligence.',
            'engagement_snapshot' => [],
            'recorded_by' => fn (array $attributes): int => ThirdPartyEngagement::query()->findOrFail($attributes['third_party_engagement_id'])->proposed_by,
            'recorded_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementEvent $event): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($event->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $snapshot = $engagement->only(['id', 'vendor_id', 'code', 'name', 'service_description', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approved_at', 'activated_at', 'exited_at', 'exit_summary', 'data_disposition_statement', 'vendor_snapshot', 'approval_snapshot', 'governed_at'])
                + ['business_owner' => $engagement->businessOwner?->only(['id', 'name', 'email']), 'proposer' => $engagement->proposer?->only(['id', 'name', 'email']), 'approver' => $engagement->approver?->only(['id', 'name', 'email'])];
            $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $event->version, 'from_status' => $event->from_status?->value, 'to_status' => $event->to_status->value, 'summary' => $event->summary, 'engagement_snapshot' => $snapshot, 'recorded_by' => $event->recorded_by, 'recorded_at' => $at->toIso8601String()];
            $event->engagement_snapshot = $snapshot;
            $event->recorded_at = $at;
            $event->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
