<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationRequestAcknowledgement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationRequestAcknowledgementFactory extends Factory
{
    protected $model = ThirdPartyCollaborationRequestAcknowledgement::class;

    public function definition(): array
    {
        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        $event = ThirdPartyEngagementCollaborationEvent::factory()->create(['third_party_engagement_collaboration_request_id' => $request->id]);
        $recipient = VendorUser::query()->findOrFail($request->recipient_vendor_user_id);
        $request->load(['reassignments', 'extensions.decision']);
        $context = $request->currentRecipientContext();

        return [
            'third_party_engagement_collaboration_request_id' => $request->id, 'latest_event_id' => $event->id,
            'recipient_context_fingerprint' => $context['fingerprint'], 'request_snapshot' => $request->attributesToArray(),
            'latest_event_snapshot' => $this->eventSnapshot($event), 'recipient_context' => $context, 'due_context' => $request->effectiveDueContext(),
            'vendor_user_id' => $recipient->id, 'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
            'acknowledged_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationRequestAcknowledgement $record): void {
            $payload = $record->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'recipient_context_fingerprint', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'vendor_user_id', 'recipient_snapshot']);
            $payload['acknowledged_at'] = $record->acknowledged_at->toIso8601String();
            $record->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }

    private function eventSnapshot(ThirdPartyEngagementCollaborationEvent $event): array
    {
        return [
            'id' => $event->id, 'third_party_engagement_collaboration_request_id' => $event->third_party_engagement_collaboration_request_id,
            'version' => $event->version, 'status' => $event->status->value, 'response_text' => $event->response_text,
            'source_reference' => $event->source_reference, 'summary' => $event->summary, 'actor_type' => $event->actor_type,
            'actor_id' => $event->actor_id, 'actor_snapshot' => $event->actor_snapshot, 'request_snapshot' => $event->request_snapshot,
            'evidence_manifest' => $event->evidence_manifest ?? [], 'recorded_at' => $event->recorded_at->toIso8601String(), 'fingerprint' => $event->fingerprint,
        ];
    }
}
