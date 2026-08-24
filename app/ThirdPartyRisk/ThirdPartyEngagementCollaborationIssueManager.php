<?php

namespace App\ThirdPartyRisk;

use App\Enums\GovernanceIssueStatus;
use App\Enums\ThirdPartyCollaborationEscalationStatus;
use App\Enums\ThirdPartyCollaborationReminderType;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Models\ThirdPartyCollaborationEscalationIssue;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Services\GovernanceIssueLifecycleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationIssueManager
{
    public function open(User $actor, ThirdPartyEngagementCollaborationEscalation $escalation, array $data): ThirdPartyCollaborationEscalationIssue
    {
        return DB::transaction(function () use ($actor, $escalation, $data): ThirdPartyCollaborationEscalationIssue {
            $requestId = ThirdPartyEngagementCollaborationEscalation::query()->whereKey($escalation->id)->value('third_party_engagement_collaboration_request_id');
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($requestId)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
            $request = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($requestId);
            VendorUser::withTrashed()->lockForUpdate()->findOrFail($request->recipient_vendor_user_id);
            $latestEvent = ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $request->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            ThirdPartyEngagementCollaborationReminder::query()->where('third_party_engagement_collaboration_request_id', $request->id)->where('type', ThirdPartyCollaborationReminderType::Overdue)->lockForUpdate()->firstOrFail();
            $locked = ThirdPartyEngagementCollaborationEscalation::query()->where('third_party_engagement_collaboration_request_id', $request->id)->lockForUpdate()->findOrFail($escalation->id);
            $acknowledgement = $locked->actions()->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')), 403);
            $validated = Validator::make($data, self::rules())->validate();

            $existing = ThirdPartyCollaborationEscalationIssue::query()->where('third_party_engagement_collaboration_escalation_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lifecycle');
            }

            if ($acknowledgement->status !== ThirdPartyCollaborationEscalationStatus::Acknowledged) {
                throw ValidationException::withMessages(['escalation' => 'Only an unresolved acknowledged escalation can enter issue management.']);
            }
            if (! $acknowledgement->target_resolution_at?->copy()->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['target_resolution_at' => 'The acknowledged target date has not ended.']);
            }
            if (! in_array($latestEvent->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)) {
                throw ValidationException::withMessages(['request' => 'The collaboration request no longer awaits a provider response.']);
            }

            $accountableIds = collect([$engagement->business_owner_id, $vendor->vendor_manager_id])->filter()->unique()->sort()->values();
            $accountableUsers = User::query()->whereKey($accountableIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owner = $accountableUsers->get($acknowledgement->actor_id)
                ?? $accountableUsers->get($engagement->business_owner_id)
                ?? $accountableUsers->get($vendor->vendor_manager_id);
            if (! $owner) {
                throw ValidationException::withMessages(['owner' => 'An active accountable owner is required for issue handoff.']);
            }

            $openedAt = now()->startOfSecond();
            $sourceSnapshot = [
                'escalation' => $locked->attributesToArray(),
                'acknowledgement' => $acknowledgement->attributesToArray(),
                'latest_event' => $this->eventSnapshot($latestEvent),
                'owner' => $owner->only(['id', 'name', 'email']),
                'opened_by' => $lockedActor->only(['id', 'name', 'email']),
                'rationale' => $validated['rationale'],
            ];
            $payload = [
                'third_party_engagement_collaboration_escalation_id' => $locked->id,
                'third_party_engagement_collaboration_escalation_action_id' => $acknowledgement->id,
                'third_party_engagement_id' => $engagement->id,
                'owner_id' => $owner->id,
                'opened_by' => $lockedActor->id,
                'title' => "Provider collaboration escalation {$locked->id} missed its internal target",
                'description' => $validated['rationale']."\n\nAcknowledged action plan: ".$acknowledgement->action_plan,
                'severity' => 'high',
                'status' => GovernanceIssueStatus::Open->value,
                'source_snapshot' => $sourceSnapshot,
                'opened_at' => $openedAt->toIso8601String(),
            ];
            $issue = ThirdPartyCollaborationEscalationIssue::query()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);
            $lifecycle = app(GovernanceIssueLifecycleManager::class)->register($issue, $lockedActor);
            $lifecycle->update(['due_at' => $acknowledgement->target_resolution_at]);

            return $issue->load('lifecycle');
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'rationale' => 'required|string|max:30000',
            'third_party_engagement_collaboration_escalation_id' => 'prohibited',
            'third_party_engagement_collaboration_escalation_action_id' => 'prohibited', 'third_party_engagement_id' => 'prohibited',
            'status' => 'prohibited', 'severity' => 'prohibited', 'owner_id' => 'prohibited', 'opened_by' => 'prohibited',
            'title' => 'prohibited', 'description' => 'prohibited', 'remediation_task_id' => 'prohibited',
            'source_snapshot' => 'prohibited', 'opened_at' => 'prohibited', 'fingerprint' => 'prohibited',
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
}
