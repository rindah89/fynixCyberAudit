<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationClosureAcknowledgementDelivery;
use App\Models\ThirdPartyCollaborationClosureAcknowledgementReceipt;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationClosureAcknowledgementReceiptFactory extends Factory
{
    protected $model = ThirdPartyCollaborationClosureAcknowledgementReceipt::class;

    public function definition(): array
    {
        $delivery = ThirdPartyCollaborationClosureAcknowledgementDelivery::factory()->create();
        $recipient = $delivery->recipient()->firstOrFail();
        $at = now()->startOfSecond();

        return [
            'third_party_collaboration_closure_acknowledgement_delivery_id' => $delivery->id,
            'third_party_collaboration_closure_acknowledgement_id' => $delivery->third_party_collaboration_closure_acknowledgement_id,
            'third_party_engagement_collaboration_request_id' => $delivery->third_party_engagement_collaboration_request_id,
            'user_id' => $recipient->id,
            'recipient_snapshot' => $recipient->only(['id', 'name', 'email']) + ['deleted_at' => $recipient->deleted_at?->toIso8601String()],
            'delivery_snapshot' => $delivery->attributesToArray(),
            'acknowledged_at' => $at,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationClosureAcknowledgementReceipt $receipt): void {
            $payload = $receipt->only([
                'third_party_collaboration_closure_acknowledgement_delivery_id',
                'third_party_collaboration_closure_acknowledgement_id',
                'third_party_engagement_collaboration_request_id', 'user_id',
                'recipient_snapshot', 'delivery_snapshot',
            ]);
            $payload['acknowledged_at'] = $receipt->acknowledged_at->toIso8601String();
            $receipt->fingerprint = hash('sha256', CanonicalJson::encode($payload));
        });
    }
}
