<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationClosureDelivery;
use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\VendorUser;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ThirdPartyCollaborationClosureDeliveryFactory extends Factory
{
    protected $model = ThirdPartyCollaborationClosureDelivery::class;

    public function definition(): array
    {
        $closure = ThirdPartyCollaborationRequestClosure::factory()->create();
        $request = $closure->collaborationRequest()->firstOrFail();
        $recipient = VendorUser::withTrashed()->findOrFail($request->current_recipient_vendor_user_id);
        $at = now()->startOfSecond();

        return [
            'third_party_collaboration_request_closure_id' => $closure->id,
            'third_party_engagement_collaboration_request_id' => $request->id,
            'vendor_user_id' => $recipient->id,
            'channel' => 'database',
            'notification_id' => Str::uuid()->toString(),
            'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']) + [
                'email_verified_at' => $recipient->email_verified_at?->toIso8601String(),
                'activated' => $recipient->hasPassword(),
                'deleted_at' => $recipient->deleted_at?->toIso8601String(),
            ],
            'closure_snapshot' => $closure->attributesToArray(),
            'attempted_at' => $at,
            'delivered_at' => $at,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationClosureDelivery $delivery): void {
            $payload = $delivery->only([
                'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id',
                'vendor_user_id', 'channel', 'notification_id', 'recipient_snapshot', 'closure_snapshot',
            ]);
            $payload['attempted_at'] = $delivery->attempted_at->toIso8601String();
            $payload['delivered_at'] = $delivery->delivered_at->toIso8601String();
            $delivery->fingerprint = hash('sha256', CanonicalJson::encode($payload));
        });
    }
}
