<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationEscalationStatus;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyCollaborationTimeliness;
use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Notifications\ThirdPartyCollaborationClosureNotification;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationClosureManager
{
    public function close(User $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyCollaborationRequestClosure
    {
        return DB::transaction(function () use ($actor, $request, $data): ThirdPartyCollaborationRequestClosure {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')), 403);
            $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $recipient = VendorUser::withTrashed()->lockForUpdate()->findOrFail($recipientContext['recipient_vendor_user_id']);
            $events = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $acceptedEvent = $events->last() ?? throw ValidationException::withMessages(['request' => 'The collaboration request has no retained event history.']);
            $acceptedResponse = $events->slice(0, -1)->last();
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $escalation = $locked->escalation()->lockForUpdate()->first();
            $escalationActions = $escalation?->actions()->orderBy('id')->lockForUpdate()->get() ?? collect();
            $latestEscalationAction = $escalationActions->last();
            $existing = $locked->closure()->lockForUpdate()->first();
            $validated = Validator::make($data, self::rules())->validate();
            abort_if($lockedActor->id === $locked->opened_by || ($acceptedEvent->actor_type === 'user' && $lockedActor->id === $acceptedEvent->actor_id), 403, 'Request closure must be separated from opening and response acceptance.');
            if ($existing || $locked->cancellation()->lockForUpdate()->exists() || $acceptedEvent->status !== ThirdPartyCollaborationStatus::Accepted || $acceptedResponse?->status !== ThirdPartyCollaborationStatus::Responded) {
                throw ValidationException::withMessages(['request' => 'Only an accepted, unclosed collaboration request can be closed once.']);
            }
            if ($extensions->contains(fn ($extension): bool => $extension->decision === null)) {
                throw ValidationException::withMessages(['request' => 'The pending due-date extension requires a decision before closure.']);
            }
            if ($escalation && $latestEscalationAction?->status !== ThirdPartyCollaborationEscalationStatus::Resolved) {
                throw ValidationException::withMessages(['request' => 'An escalated request requires independent escalation resolution before closure.']);
            }
            $at = now()->startOfSecond();
            $dueContext = $locked->setRelation('extensions', $extensions)->effectiveDueContext();
            $responseAt = $acceptedResponse->recorded_at->copy()->utc()->startOfSecond();
            $dueDate = Carbon::createFromFormat('Y-m-d', $dueContext['due_at'], 'UTC')->startOfDay();
            $responseDate = $responseAt->copy()->startOfDay();
            $daysLate = $responseDate->greaterThan($dueDate) ? (int) $dueDate->diffInDays($responseDate) : 0;
            $timelinessStatus = $daysLate === 0 ? ThirdPartyCollaborationTimeliness::OnTime->value : ThirdPartyCollaborationTimeliness::Late->value;
            $fingerprintedDueContext = [
                'due_at' => $dueContext['due_at'], 'fingerprint' => $dueContext['fingerprint'],
                'extension_id' => $dueContext['extension_id'], 'decision_id' => $dueContext['decision_id'],
            ];
            $timelinessPayload = [
                'accepted_event_id' => $acceptedEvent->id,
                'response_recorded_at' => $responseAt->toIso8601String(),
                'due_context' => $fingerprintedDueContext,
                'calendar_timezone' => 'UTC',
                'timeliness_status' => $timelinessStatus,
                'days_late' => $daysLate,
            ];
            $payload = [
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'accepted_event_id' => $acceptedEvent->id,
                'request_snapshot' => $locked->attributesToArray(),
                'accepted_event_snapshot' => ['acceptance' => $this->eventSnapshot($acceptedEvent), 'response' => $this->eventSnapshot($acceptedResponse)],
                'recipient_context' => $recipientContext,
                'due_context' => $dueContext,
                'escalation_snapshot' => $escalation ? ['escalation' => $escalation->attributesToArray(), 'latest_action' => $latestEscalationAction?->attributesToArray()] : null,
                'response_recorded_at' => $responseAt->toIso8601String(),
                'timeliness_status' => $timelinessStatus,
                'days_late' => $daysLate,
                'calendar_timezone' => 'UTC',
                'timeliness_fingerprint' => hash('sha256', json_encode($timelinessPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'fingerprint_version' => 'closure/v2',
                'summary' => $validated['summary'],
                'closed_by' => $lockedActor->id,
                'actor_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'closed_at' => $at->toIso8601String(),
            ];

            $closure = $locked->closure()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            abort_unless($recipient->vendor_id === $engagement->vendor_id, 422, 'The retained collaboration recipient does not belong to this engagement vendor.');
            $notificationId = Str::uuid()->toString();
            $attemptedAt = now()->startOfSecond();
            $recipient->notifyNow(new ThirdPartyCollaborationClosureNotification($notificationId, $engagement->code, $locked->subject, $locked->id));
            if (! DB::table('notifications')->where('id', $notificationId)
                ->where('notifiable_type', VendorUser::class)->where('notifiable_id', $recipient->id)->exists()) {
                throw new \LogicException('The collaboration closure notification was not accepted by the database delivery channel.');
            }
            $deliveredAt = now()->startOfSecond();
            $deliveryPayload = [
                'third_party_collaboration_request_closure_id' => $closure->id,
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'vendor_user_id' => $recipient->id,
                'channel' => 'database',
                'notification_id' => $notificationId,
                'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']) + [
                    'email_verified_at' => $recipient->email_verified_at?->toIso8601String(),
                    'activated' => $recipient->hasPassword(),
                    'deleted_at' => $recipient->deleted_at?->toIso8601String(),
                ],
                'closure_snapshot' => $closure->attributesToArray(),
                'attempted_at' => $attemptedAt->toIso8601String(),
                'delivered_at' => $deliveredAt->toIso8601String(),
            ];
            $closure->delivery()->create($deliveryPayload + ['fingerprint' => hash('sha256', CanonicalJson::encode($deliveryPayload))]);

            return $closure->load(['actor:id,name,email', 'delivery.recipient:id,vendor_id,name,email']);
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'third_party_engagement_collaboration_request_id' => 'prohibited', 'accepted_event_id' => 'prohibited',
            'request_snapshot' => 'prohibited', 'accepted_event_snapshot' => 'prohibited', 'recipient_context' => 'prohibited',
            'due_context' => 'prohibited', 'escalation_snapshot' => 'prohibited', 'response_recorded_at' => 'prohibited',
            'timeliness_status' => 'prohibited', 'days_late' => 'prohibited', 'calendar_timezone' => 'prohibited',
            'timeliness_fingerprint' => 'prohibited', 'fingerprint_version' => 'prohibited', 'closed_by' => 'prohibited',
            'actor_snapshot' => 'prohibited', 'closed_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
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
