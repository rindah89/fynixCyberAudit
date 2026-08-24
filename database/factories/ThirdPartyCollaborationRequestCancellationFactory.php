<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationRequestCancellation;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationRequestCancellationFactory extends Factory
{
    protected $model = ThirdPartyCollaborationRequestCancellation::class;

    public function definition(): array
    {
        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        $latestEvent = ThirdPartyEngagementCollaborationEvent::factory()->create(['third_party_engagement_collaboration_request_id' => $request->id]);
        $request->load(['extensions.decision', 'reassignments']);
        $actor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));

        return [
            'third_party_engagement_collaboration_request_id' => $request->id,
            'latest_event_id' => $latestEvent->id,
            'request_snapshot' => $request->attributesToArray(),
            'latest_event_snapshot' => [
                'id' => $latestEvent->id,
                'third_party_engagement_collaboration_request_id' => $latestEvent->third_party_engagement_collaboration_request_id,
                'version' => $latestEvent->version,
                'status' => $latestEvent->status->value,
                'response_text' => $latestEvent->response_text,
                'source_reference' => $latestEvent->source_reference,
                'summary' => $latestEvent->summary,
                'actor_type' => $latestEvent->actor_type,
                'actor_id' => $latestEvent->actor_id,
                'actor_snapshot' => $latestEvent->actor_snapshot,
                'request_snapshot' => $latestEvent->request_snapshot,
                'evidence_manifest' => $latestEvent->evidence_manifest ?? [],
                'recorded_at' => $latestEvent->recorded_at->toIso8601String(),
                'fingerprint' => $latestEvent->fingerprint,
            ],
            'recipient_context' => $request->currentRecipientContext(),
            'due_context' => $request->effectiveDueContext(),
            'reason' => 'The request is no longer required.',
            'cancelled_by' => $actor->id,
            'actor_snapshot' => $actor->only(['id', 'name', 'email']),
            'cancelled_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationRequestCancellation $record): void {
            $payload = $record->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'reason', 'cancelled_by', 'actor_snapshot']);
            $payload['cancelled_at'] = $record->cancelled_at->toIso8601String();
            $record->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
