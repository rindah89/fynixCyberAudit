<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyCollaborationRecipientReassignment;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationRecipientManager
{
    public function reassign(User $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyCollaborationRecipientReassignment
    {
        return DB::transaction(function () use ($actor, $request, $data): ThirdPartyCollaborationRecipientReassignment {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')), 403);
            $history = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $currentContext = $locked->setRelation('reassignments', $history)->currentRecipientContext();
            $validated = Validator::make($data, self::rules())->validate();
            $recipientIds = collect([(int) $currentContext['recipient_vendor_user_id'], (int) $validated['recipient_vendor_user_id']])->unique()->sort()->values();
            $recipients = VendorUser::withTrashed()->whereIn('id', $recipientIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $recipients->get((int) $currentContext['recipient_vendor_user_id']);
            $to = $recipients->get((int) $validated['recipient_vendor_user_id']);
            $latestEvent = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            abort_unless($from && $to?->vendor_id === $engagement->vendor_id && ! $to->trashed() && $to->hasPassword(), 403);
            if ($to->id === $from->id) {
                throw ValidationException::withMessages(['recipient_vendor_user_id' => 'The replacement must differ from the current recipient.']);
            }
            if (! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                || ! in_array($latestEvent->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)
                || $locked->escalation()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'Only an awaiting, non-escalated collaboration request can be reassigned.']);
            }
            if ($history->count() >= 20) {
                throw ValidationException::withMessages(['request' => 'A collaboration request is limited to 20 recipient reassignments.']);
            }
            if ($extensions->contains(fn ($extension): bool => $extension->decision === null)) {
                throw ValidationException::withMessages(['request' => 'The pending due-date extension requires a decision before recipient reassignment.']);
            }
            $at = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'version' => ((int) $history->max('version')) + 1,
                'from_vendor_user_id' => $from->id,
                'to_vendor_user_id' => $to->id,
                'from_recipient_snapshot' => $from->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
                'to_recipient_snapshot' => $to->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
                'prior_recipient_context' => $currentContext,
                'request_snapshot' => $locked->attributesToArray(),
                'reason' => $validated['reason'],
                'reassigned_by' => $lockedActor->id,
                'actor_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'reassigned_at' => $at->toIso8601String(),
            ];

            $record = $locked->reassignments()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            $notificationIds = $locked->reminders()->where('vendor_user_id', $from->id)->lockForUpdate()->pluck('notification_id');
            if ($notificationIds->isNotEmpty()) {
                DB::table('notifications')->whereIn('id', $notificationIds)
                    ->where('notifiable_type', VendorUser::class)->where('notifiable_id', $from->id)->delete();
            }

            return $record;
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'recipient_vendor_user_id' => 'required|integer', 'reason' => 'required|string|max:30000',
            'version' => 'prohibited', 'from_vendor_user_id' => 'prohibited', 'to_vendor_user_id' => 'prohibited',
            'from_recipient_snapshot' => 'prohibited', 'to_recipient_snapshot' => 'prohibited', 'prior_recipient_context' => 'prohibited', 'request_snapshot' => 'prohibited',
            'reassigned_by' => 'prohibited', 'actor_snapshot' => 'prohibited', 'reassigned_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
