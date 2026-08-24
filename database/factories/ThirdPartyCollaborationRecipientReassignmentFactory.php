<?php

namespace Database\Factories;

use App\Models\ThirdPartyCollaborationRecipientReassignment;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationRecipientReassignmentFactory extends Factory
{
    protected $model = ThirdPartyCollaborationRecipientReassignment::class;

    public function definition(): array
    {
        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        $from = VendorUser::query()->findOrFail($request->recipient_vendor_user_id);
        $to = VendorUser::factory()->create(['vendor_id' => $from->vendor_id]);
        $actor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));

        return [
            'third_party_engagement_collaboration_request_id' => $request->id,
            'version' => 1,
            'from_vendor_user_id' => $from->id,
            'to_vendor_user_id' => $to->id,
            'from_recipient_snapshot' => $from->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
            'to_recipient_snapshot' => $to->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
            'prior_recipient_context' => $request->currentRecipientContext(),
            'request_snapshot' => $request->attributesToArray(),
            'reason' => 'The original provider contact is unavailable.',
            'reassigned_by' => $actor->id,
            'actor_snapshot' => $actor->only(['id', 'name', 'email']),
            'reassigned_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationRecipientReassignment $record): void {
            $payload = $record->only(['third_party_engagement_collaboration_request_id', 'version', 'from_vendor_user_id', 'to_vendor_user_id', 'from_recipient_snapshot', 'to_recipient_snapshot', 'prior_recipient_context', 'request_snapshot', 'reason', 'reassigned_by', 'actor_snapshot', 'reassigned_at']);
            $payload['reassigned_at'] = $record->reassigned_at->toIso8601String();
            $record->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
