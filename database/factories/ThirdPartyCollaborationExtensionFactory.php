<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationExtension;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationExtensionFactory extends Factory
{
    protected $model = ThirdPartyCollaborationExtension::class;

    public function definition(): array
    {
        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        $recipient = VendorUser::query()->findOrFail($request->recipient_vendor_user_id);

        return [
            'third_party_engagement_collaboration_request_id' => $request->id,
            'version' => 1,
            'proposed_due_at' => $request->due_at->copy()->addDays(7),
            'reason' => 'Additional time is required to complete the requested response.',
            'recipient_vendor_user_id' => $recipient->id,
            'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
            'request_snapshot' => $request->attributesToArray(),
            'current_due_context' => $request->effectiveDueContext(),
            'requested_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationExtension $extension): void {
            $payload = $extension->only(['third_party_engagement_collaboration_request_id', 'version', 'proposed_due_at', 'reason', 'recipient_vendor_user_id', 'recipient_snapshot', 'request_snapshot', 'current_due_context', 'requested_at']);
            $payload['proposed_due_at'] = $extension->proposed_due_at->toDateString();
            $payload['requested_at'] = $extension->requested_at->toIso8601String();
            $extension->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
