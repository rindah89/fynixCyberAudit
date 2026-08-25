<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyCollaborationRequestAcknowledgement;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationAcknowledgementManager
{
    public function acknowledge(VendorUser $actor, ThirdPartyEngagementCollaborationRequest $request): ThirdPartyCollaborationRequestAcknowledgement
    {
        return DB::transaction(function () use ($actor, $request): ThirdPartyCollaborationRequestAcknowledgement {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);
            $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $lockedActor = VendorUser::query()->whereNull('deleted_at')->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor?->hasPassword() && $lockedActor->id === $recipientContext['recipient_vendor_user_id'] && $lockedActor->vendor_id === $engagement->vendor_id, 403);
            $latestEvent = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $acknowledgements = $locked->acknowledgements()->orderBy('id')->lockForUpdate()->get();
            if (! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                || ! in_array($latestEvent->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)
                || $locked->cancellation()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'Only the exact current recipient can acknowledge an awaiting request.']);
            }
            if ($acknowledgements->count() >= 21 || $acknowledgements->contains(fn (ThirdPartyCollaborationRequestAcknowledgement $acknowledgement): bool => $acknowledgement->recipient_context_fingerprint === $recipientContext['fingerprint'])) {
                throw ValidationException::withMessages(['request' => 'The current recipient assignment has already acknowledged this request.']);
            }
            $dueContext = $locked->setRelation('extensions', $extensions)->effectiveDueContext();
            $at = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'latest_event_id' => $latestEvent->id,
                'recipient_context_fingerprint' => $recipientContext['fingerprint'],
                'request_snapshot' => $locked->attributesToArray(),
                'latest_event_snapshot' => $this->eventSnapshot($latestEvent),
                'recipient_context' => $recipientContext,
                'due_context' => $dueContext,
                'vendor_user_id' => $lockedActor->id,
                'recipient_snapshot' => $lockedActor->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
                'acknowledged_at' => $at->toIso8601String(),
            ];

            return $locked->acknowledgements()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
        }, 3);
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

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
