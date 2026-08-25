<?php

namespace Database\Factories;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyCollaborationTimeliness;
use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ThirdPartyCollaborationRequestClosureFactory extends Factory
{
    protected $model = ThirdPartyCollaborationRequestClosure::class;

    public function definition(): array
    {
        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        ThirdPartyEngagementCollaborationEvent::factory()->create([
            'third_party_engagement_collaboration_request_id' => $request->id,
            'version' => 1,
            'status' => ThirdPartyCollaborationStatus::Requested,
        ]);
        $response = ThirdPartyEngagementCollaborationEvent::factory()->create([
            'third_party_engagement_collaboration_request_id' => $request->id,
            'version' => 2,
            'status' => ThirdPartyCollaborationStatus::Responded,
        ]);
        $event = ThirdPartyEngagementCollaborationEvent::factory()->create([
            'third_party_engagement_collaboration_request_id' => $request->id,
            'version' => 3,
            'status' => ThirdPartyCollaborationStatus::Accepted,
        ]);
        $actor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $request->load(['reassignments', 'extensions.decision']);
        $dueContext = $request->effectiveDueContext();
        $responseDate = $response->recorded_at->copy()->utc()->startOfDay();
        $dueDate = Carbon::createFromFormat('Y-m-d', $dueContext['due_at'], 'UTC')->startOfDay();
        $daysLate = $responseDate->greaterThan($dueDate) ? (int) $dueDate->diffInDays($responseDate) : 0;
        $timelinessStatus = $daysLate === 0 ? ThirdPartyCollaborationTimeliness::OnTime : ThirdPartyCollaborationTimeliness::Late;
        $fingerprintedDueContext = [
            'due_at' => $dueContext['due_at'], 'fingerprint' => $dueContext['fingerprint'],
            'extension_id' => $dueContext['extension_id'], 'decision_id' => $dueContext['decision_id'],
        ];
        $timelinessPayload = [
            'accepted_event_id' => $event->id,
            'response_recorded_at' => $response->recorded_at->copy()->utc()->startOfSecond()->toIso8601String(),
            'due_context' => $fingerprintedDueContext,
            'calendar_timezone' => 'UTC',
            'timeliness_status' => $timelinessStatus->value,
            'days_late' => $daysLate,
        ];

        return [
            'third_party_engagement_collaboration_request_id' => $request->id, 'accepted_event_id' => $event->id,
            'request_snapshot' => $request->attributesToArray(),
            'accepted_event_snapshot' => ['acceptance' => $this->eventSnapshot($event), 'response' => $this->eventSnapshot($response)],
            'recipient_context' => $request->currentRecipientContext(), 'due_context' => $dueContext,
            'escalation_snapshot' => null, 'response_recorded_at' => $response->recorded_at->copy()->utc()->startOfSecond(),
            'timeliness_status' => $timelinessStatus, 'days_late' => $daysLate,
            'calendar_timezone' => 'UTC',
            'timeliness_fingerprint' => hash('sha256', CanonicalJson::encode($timelinessPayload)),
            'fingerprint_version' => 'closure/v2',
            'summary' => 'The accepted response completed the in-product request workflow.',
            'closed_by' => $actor->id, 'actor_snapshot' => $actor->only(['id', 'name', 'email']),
            'closed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationRequestClosure $record): void {
            $payload = $record->only(['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot']);
            $payload['response_recorded_at'] = $record->response_recorded_at->toIso8601String();
            $payload['timeliness_status'] = $record->timeliness_status->value;
            $payload['days_late'] = $record->days_late;
            $payload['calendar_timezone'] = $record->calendar_timezone;
            $payload['timeliness_fingerprint'] = $record->timeliness_fingerprint;
            $payload['fingerprint_version'] = $record->fingerprint_version;
            $payload += $record->only(['summary', 'closed_by', 'actor_snapshot']);
            $payload['closed_at'] = $record->closed_at->toIso8601String();
            $record->fingerprint = hash('sha256', CanonicalJson::encode($payload));
        });
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
