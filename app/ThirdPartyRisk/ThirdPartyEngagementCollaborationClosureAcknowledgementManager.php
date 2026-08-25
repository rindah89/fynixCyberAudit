<?php

namespace App\ThirdPartyRisk;

use App\Models\ThirdPartyCollaborationClosureAcknowledgement;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationClosureAcknowledgementManager
{
    public function acknowledge(VendorUser $actor, ThirdPartyEngagementCollaborationRequest $request): ThirdPartyCollaborationClosureAcknowledgement
    {
        return DB::transaction(function () use ($actor, $request): ThirdPartyCollaborationClosureAcknowledgement {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);
            $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $lockedActor = VendorUser::query()->whereNull('deleted_at')->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor?->hasPassword() && $lockedActor->id === $recipientContext['recipient_vendor_user_id'] && $lockedActor->vendor_id === $engagement->vendor_id, 403);
            ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $closure = $locked->closure()->lockForUpdate()->first();
            $delivery = $closure?->delivery()->lockForUpdate()->first();
            if (! $closure || ! $delivery || $delivery->vendor_user_id !== $lockedActor->id) {
                throw ValidationException::withMessages(['request' => 'Only the exact delivered closure recipient can acknowledge closure.']);
            }
            if ($closure->acknowledgement()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'This collaboration closure has already been acknowledged.']);
            }
            $at = now()->startOfSecond();
            $deliverySnapshot = (clone $delivery)
                ->makeVisible(['id', 'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot'])
                ->attributesToArray();
            $payload = [
                'third_party_collaboration_request_closure_id' => $closure->id,
                'third_party_collaboration_closure_delivery_id' => $delivery->id,
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'vendor_user_id' => $lockedActor->id,
                'recipient_snapshot' => $lockedActor->only(['id', 'vendor_id', 'name', 'email', 'is_primary']) + [
                    'email_verified_at' => $lockedActor->email_verified_at?->toIso8601String(),
                    'activated' => $lockedActor->hasPassword(),
                    'deleted_at' => null,
                ],
                'closure_snapshot' => $closure->attributesToArray(),
                'delivery_snapshot' => $deliverySnapshot,
                'acknowledged_at' => $at->toIso8601String(),
            ];

            return $closure->acknowledgement()->create($payload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
        }, 3);
    }
}
