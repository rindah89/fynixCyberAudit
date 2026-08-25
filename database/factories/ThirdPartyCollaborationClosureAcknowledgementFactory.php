<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationClosureAcknowledgement;
use App\Models\ThirdPartyCollaborationClosureDelivery;
use App\Models\VendorUser;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationClosureAcknowledgementFactory extends Factory
{
    protected $model = ThirdPartyCollaborationClosureAcknowledgement::class;

    public function definition(): array
    {
        $delivery = ThirdPartyCollaborationClosureDelivery::factory()->create();
        $closure = $delivery->closure()->firstOrFail();
        $recipient = VendorUser::withTrashed()->findOrFail($delivery->vendor_user_id);
        $at = now()->startOfSecond();
        $deliverySnapshot = (clone $delivery)
            ->makeVisible(['id', 'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot'])
            ->attributesToArray();

        return [
            'third_party_collaboration_request_closure_id' => $closure->id,
            'third_party_collaboration_closure_delivery_id' => $delivery->id,
            'third_party_engagement_collaboration_request_id' => $delivery->third_party_engagement_collaboration_request_id,
            'vendor_user_id' => $recipient->id,
            'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']) + [
                'email_verified_at' => $recipient->email_verified_at?->toIso8601String(),
                'activated' => $recipient->hasPassword(),
                'deleted_at' => $recipient->deleted_at?->toIso8601String(),
            ],
            'closure_snapshot' => $closure->attributesToArray(),
            'delivery_snapshot' => $deliverySnapshot,
            'acknowledged_at' => $at,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationClosureAcknowledgement $acknowledgement): void {
            $payload = $acknowledgement->only([
                'third_party_collaboration_request_closure_id', 'third_party_collaboration_closure_delivery_id',
                'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot',
                'closure_snapshot', 'delivery_snapshot',
            ]);
            $payload['acknowledged_at'] = $acknowledgement->acknowledged_at->toIso8601String();
            $acknowledgement->fingerprint = hash('sha256', CanonicalJson::encode($payload));
        });
    }
}
