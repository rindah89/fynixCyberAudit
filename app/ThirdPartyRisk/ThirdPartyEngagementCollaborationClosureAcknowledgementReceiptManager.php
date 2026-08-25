<?php

namespace App\ThirdPartyRisk;

use App\Models\ThirdPartyCollaborationClosureAcknowledgementDelivery;
use App\Models\ThirdPartyCollaborationClosureAcknowledgementReceipt;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationClosureAcknowledgementReceiptManager
{
    public function acknowledge(User $actor, ThirdPartyCollaborationClosureAcknowledgementDelivery $delivery): ThirdPartyCollaborationClosureAcknowledgementReceipt
    {
        return DB::transaction(function () use ($actor, $delivery): ThirdPartyCollaborationClosureAcknowledgementReceipt {
            $requestId = ThirdPartyCollaborationClosureAcknowledgementDelivery::query()->whereKey($delivery->id)->value('third_party_engagement_collaboration_request_id');
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($requestId)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $request = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($requestId);
            $request->reassignments()->orderBy('version')->lockForUpdate()->get();
            ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $request->id)->orderBy('version')->lockForUpdate()->get();
            $request->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $closure = $request->closure()->lockForUpdate()->firstOrFail();
            $closure->delivery()->lockForUpdate()->firstOrFail();
            $acknowledgement = $closure->acknowledgement()->lockForUpdate()->firstOrFail();
            $lockedDelivery = $acknowledgement->internalDeliveries()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereNull('deleted_at')->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && $lockedActor->id === $lockedDelivery->user_id, 403);
            if ($lockedDelivery->receipt()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['delivery' => 'This internal acknowledgement delivery has already been acknowledged.']);
            }

            $at = now()->startOfSecond();
            $deliverySnapshot = $lockedDelivery->attributesToArray();
            $payload = [
                'third_party_collaboration_closure_acknowledgement_delivery_id' => $lockedDelivery->id,
                'third_party_collaboration_closure_acknowledgement_id' => $acknowledgement->id,
                'third_party_engagement_collaboration_request_id' => $request->id,
                'user_id' => $lockedActor->id,
                'recipient_snapshot' => $lockedActor->only(['id', 'name', 'email']) + ['deleted_at' => null],
                'delivery_snapshot' => $deliverySnapshot,
                'acknowledged_at' => $at->toIso8601String(),
            ];

            return $lockedDelivery->receipt()->create($payload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
            ])->load('recipient:id,name,email');
        }, 3);
    }
}
