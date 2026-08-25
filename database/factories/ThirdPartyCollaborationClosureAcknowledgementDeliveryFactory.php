<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationClosureAcknowledgement;
use App\Models\ThirdPartyCollaborationClosureAcknowledgementDelivery;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ThirdPartyCollaborationClosureAcknowledgementDeliveryFactory extends Factory
{
    protected $model = ThirdPartyCollaborationClosureAcknowledgementDelivery::class;

    public function definition(): array
    {
        $acknowledgement = ThirdPartyCollaborationClosureAcknowledgement::factory()->create();
        $request = $acknowledgement->collaborationRequest()->firstOrFail();
        $engagement = $request->engagement()->firstOrFail();
        $recipient = User::withTrashed()->findOrFail($engagement->business_owner_id);
        $at = now()->startOfSecond();
        $acknowledgementSnapshot = (clone $acknowledgement)
            ->makeVisible(['id', 'third_party_collaboration_request_closure_id', 'third_party_collaboration_closure_delivery_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot', 'delivery_snapshot'])
            ->attributesToArray();

        return [
            'third_party_collaboration_closure_acknowledgement_id' => $acknowledgement->id,
            'third_party_collaboration_request_closure_id' => $acknowledgement->third_party_collaboration_request_closure_id,
            'third_party_engagement_collaboration_request_id' => $request->id,
            'user_id' => $recipient->id,
            'accountability_roles' => ['business_owner'],
            'recipient_snapshot' => $recipient->only(['id', 'name', 'email']) + ['deleted_at' => $recipient->deleted_at?->toIso8601String()],
            'acknowledgement_snapshot' => $acknowledgementSnapshot,
            'channel' => 'database',
            'notification_id' => Str::uuid()->toString(),
            'attempted_at' => $at,
            'delivered_at' => $at,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationClosureAcknowledgementDelivery $delivery): void {
            $payload = $delivery->only([
                'third_party_collaboration_closure_acknowledgement_id', 'third_party_collaboration_request_closure_id',
                'third_party_engagement_collaboration_request_id', 'user_id', 'accountability_roles',
                'recipient_snapshot', 'acknowledgement_snapshot', 'channel', 'notification_id',
            ]);
            $payload['attempted_at'] = $delivery->attempted_at->toIso8601String();
            $payload['delivered_at'] = $delivery->delivered_at->toIso8601String();
            $delivery->fingerprint = hash('sha256', CanonicalJson::encode($payload));
        });
    }
}
