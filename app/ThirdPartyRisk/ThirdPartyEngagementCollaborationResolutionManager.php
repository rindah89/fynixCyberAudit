<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationEscalationStatus;
use App\Enums\ThirdPartyCollaborationReminderType;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\Models\ThirdPartyEngagementCollaborationEscalationAction;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationResolutionManager
{
    public function acknowledge(User $actor, ThirdPartyEngagementCollaborationEscalation $escalation, array $data): ThirdPartyEngagementCollaborationEscalationAction
    {
        return DB::transaction(function () use ($actor, $escalation, $data): ThirdPartyEngagementCollaborationEscalationAction {
            [$locked, $engagement, $vendor] = $this->lockGraph($escalation);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && in_array($lockedActor->id, [$engagement->business_owner_id, $vendor->vendor_manager_id], true), 403);
            $data = Validator::make($data, self::acknowledgementRules())->validate();
            if ($locked->actions()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['escalation' => 'This escalation has already been acknowledged.']);
            }

            return $this->appendAction(
                $locked,
                ThirdPartyCollaborationEscalationStatus::Acknowledged,
                $lockedActor,
                $data['summary'],
                $data['action_plan'],
                $data['target_resolution_at'],
            );
        }, 3);
    }

    public function resolve(User $actor, ThirdPartyEngagementCollaborationEscalation $escalation, array $data): ThirdPartyEngagementCollaborationEscalationAction
    {
        return DB::transaction(function () use ($actor, $escalation, $data): ThirdPartyEngagementCollaborationEscalationAction {
            [$locked, $engagement, $vendor, $latestEvent] = $this->lockGraph($escalation);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')
                || in_array($lockedActor->id, [$engagement->business_owner_id, $vendor->vendor_manager_id], true)), 403);
            $data = Validator::make($data, self::resolutionRules())->validate();
            $acknowledgement = $locked->actions()->orderByDesc('version')->lockForUpdate()->first();
            if (! $acknowledgement || $acknowledgement->status !== ThirdPartyCollaborationEscalationStatus::Acknowledged) {
                throw ValidationException::withMessages(['escalation' => 'The escalation must be acknowledged before resolution.']);
            }
            abort_if($lockedActor->id === $acknowledgement->actor_id, 403, 'Resolution must be separated from acknowledgement.');
            if ($latestEvent->status !== ThirdPartyCollaborationStatus::Accepted
                || $latestEvent->version <= (int) data_get($locked->event_snapshot, 'version')) {
                throw ValidationException::withMessages(['request' => 'Resolution requires a provider response accepted after escalation.']);
            }
            abort_if($lockedActor->id === $latestEvent->actor_id, 403, 'Resolution must be separated from response acceptance.');

            return $this->appendAction(
                $locked,
                ThirdPartyCollaborationEscalationStatus::Resolved,
                $lockedActor,
                $data['summary'],
                null,
                null,
                $this->eventSnapshot($latestEvent),
            );
        }, 3);
    }

    public static function acknowledgementRules(): array
    {
        return [
            'summary' => 'required|string|max:30000', 'action_plan' => 'required|string|max:30000', 'target_resolution_at' => 'required|date_format:Y-m-d|after_or_equal:today',
            'status' => 'prohibited', 'version' => 'prohibited', 'actor_id' => 'prohibited', 'actor_snapshot' => 'prohibited',
            'escalation_snapshot' => 'prohibited', 'accepted_event_snapshot' => 'prohibited', 'recorded_at' => 'prohibited',
            'third_party_engagement_collaboration_escalation_id' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    public static function resolutionRules(): array
    {
        return [
            'summary' => 'required|string|max:30000', 'action_plan' => 'prohibited', 'target_resolution_at' => 'prohibited',
            'status' => 'prohibited', 'version' => 'prohibited', 'actor_id' => 'prohibited', 'actor_snapshot' => 'prohibited',
            'escalation_snapshot' => 'prohibited', 'accepted_event_snapshot' => 'prohibited', 'recorded_at' => 'prohibited',
            'third_party_engagement_collaboration_escalation_id' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    private function lockGraph(ThirdPartyEngagementCollaborationEscalation $escalation): array
    {
        $requestId = ThirdPartyEngagementCollaborationEscalation::query()->whereKey($escalation->id)->value('third_party_engagement_collaboration_request_id');
        $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($requestId)->value('third_party_engagement_id');
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
        $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
        $request = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($requestId);
        VendorUser::withTrashed()->lockForUpdate()->findOrFail($request->recipient_vendor_user_id);
        $latestEvent = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $request->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $extensions = $request->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
        $dueContext = $request->setRelation('extensions', $extensions)->effectiveDueContext();
        ThirdPartyEngagementCollaborationReminder::query()->where('third_party_engagement_collaboration_request_id', $request->id)->where('due_context_fingerprint', $dueContext['fingerprint'])->where('type', ThirdPartyCollaborationReminderType::Overdue)->lockForUpdate()->firstOrFail();
        $locked = ThirdPartyEngagementCollaborationEscalation::query()->where('third_party_engagement_collaboration_request_id', $request->id)->lockForUpdate()->findOrFail($escalation->id);

        return [$locked, $engagement, $vendor, $latestEvent];
    }

    private function appendAction(ThirdPartyEngagementCollaborationEscalation $escalation, ThirdPartyCollaborationEscalationStatus $status, User $actor, string $summary, ?string $actionPlan, ?string $targetResolutionAt, ?array $acceptedEvent = null): ThirdPartyEngagementCollaborationEscalationAction
    {
        $at = now()->startOfSecond();
        $payload = [
            'third_party_engagement_collaboration_escalation_id' => $escalation->id,
            'version' => ((int) $escalation->actions()->max('version')) + 1,
            'status' => $status->value,
            'summary' => $summary,
            'action_plan' => $actionPlan,
            'target_resolution_at' => $targetResolutionAt,
            'actor_id' => $actor->id,
            'actor_snapshot' => $actor->only(['id', 'name', 'email']),
            'escalation_snapshot' => $escalation->attributesToArray(),
            'accepted_event_snapshot' => $acceptedEvent,
            'recorded_at' => $at->toIso8601String(),
        ];

        return $escalation->actions()->create($payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
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
}
