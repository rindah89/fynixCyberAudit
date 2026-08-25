<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyCollaborationRequestCancellation;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationCancellationManager
{
    public function cancel(User $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyCollaborationRequestCancellation
    {
        return DB::transaction(function () use ($actor, $request, $data): ThirdPartyCollaborationRequestCancellation {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')), 403);
            $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
            VendorUser::withTrashed()->lockForUpdate()->findOrFail($recipientContext['recipient_vendor_user_id']);
            $latestEvent = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $reminders = $locked->reminders()->orderBy('id')->lockForUpdate()->get();
            $existing = $locked->cancellation()->lockForUpdate()->first();
            $validated = Validator::make($data, self::rules())->validate();
            if ($existing || ! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                || ! in_array($latestEvent->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)
                || $locked->escalation()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'Only an awaiting, non-escalated collaboration request can be cancelled once.']);
            }
            if ($extensions->contains(fn ($extension): bool => $extension->decision === null)) {
                throw ValidationException::withMessages(['request' => 'The pending due-date extension requires a decision before cancellation.']);
            }
            $dueContext = $locked->setRelation('extensions', $extensions)->effectiveDueContext();
            $at = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'latest_event_id' => $latestEvent->id,
                'request_snapshot' => $locked->attributesToArray(),
                'latest_event_snapshot' => $this->eventSnapshot($latestEvent),
                'recipient_context' => $recipientContext,
                'due_context' => $dueContext,
                'reason' => $validated['reason'],
                'cancelled_by' => $lockedActor->id,
                'actor_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'cancelled_at' => $at->toIso8601String(),
            ];
            $record = $locked->cancellation()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            $notificationIds = $reminders->pluck('notification_id')->filter();
            if ($notificationIds->isNotEmpty()) {
                DatabaseNotification::query()->whereIn('id', $notificationIds)
                    ->where('notifiable_type', VendorUser::class)->where('notifiable_id', $recipientContext['recipient_vendor_user_id'])->delete();
            }

            return $record->load('actor:id,name,email');
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'reason' => 'required|string|max:30000',
            'third_party_engagement_collaboration_request_id' => 'prohibited', 'latest_event_id' => 'prohibited',
            'request_snapshot' => 'prohibited', 'latest_event_snapshot' => 'prohibited', 'recipient_context' => 'prohibited', 'due_context' => 'prohibited',
            'cancelled_by' => 'prohibited', 'actor_snapshot' => 'prohibited', 'cancelled_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function eventSnapshot(ThirdPartyEngagementCollaborationEvent $event): array
    {
        return [
            'id' => $event->id,
            'third_party_engagement_collaboration_request_id' => $event->third_party_engagement_collaboration_request_id,
            'version' => $event->version,
            'status' => $event->status->value,
            'response_text' => $event->response_text,
            'source_reference' => $event->source_reference,
            'summary' => $event->summary,
            'actor_type' => $event->actor_type,
            'actor_id' => $event->actor_id,
            'actor_snapshot' => $event->actor_snapshot,
            'request_snapshot' => $event->request_snapshot,
            'evidence_manifest' => $event->evidence_manifest ?? [],
            'recorded_at' => $event->recorded_at->toIso8601String(),
            'fingerprint' => $event->fingerprint,
        ];
    }
}
