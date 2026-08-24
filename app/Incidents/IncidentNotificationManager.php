<?php

namespace App\Incidents;

use App\Enums\IncidentNotificationAudience;
use App\Enums\IncidentNotificationStatus;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\IncidentNotificationEvent;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentNotificationManager
{
    /** @param array{audience: string, framework?: string|null, recipient: string, deadline_at?: string|null, rationale: string} $data */
    public function register(User $actor, Incident $incident, array $data): IncidentNotification
    {
        Enterprise::assertEnabled('incidents');

        return DB::transaction(function () use ($actor, $incident, $data): IncidentNotification {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->authorize($actor);
            $this->assertGoverned($lockedIncident);
            $validated = Validator::make($data, self::registerRules())->validate();
            if ($lockedIncident->notifications()->count() >= 100) {
                throw ValidationException::withMessages(['incident' => 'An incident is limited to 100 governed notification records.']);
            }

            $recordedAt = now();
            $notification = $lockedIncident->notifications()->create([
                'audience' => $validated['audience'], 'framework' => $validated['framework'] ?? null,
                'recipient' => $validated['recipient'], 'status' => IncidentNotificationStatus::AssessmentPending,
                'deadline_at' => $validated['deadline_at'] ?? null, 'governed_at' => $recordedAt,
            ]);
            $after = $this->snapshot($notification, $lockedIncident);
            $this->appendEvent($notification, $actor, 1, 'registered', null, $after, $validated['rationale'], $recordedAt);

            return $notification->load(['events.actor:id,name']);
        }, 3);
    }

    /** @param array{status?: string, framework?: string|null, recipient?: string, deadline_at?: string|null, delivery_reference?: string|null, rationale: string} $data */
    public function recordDecision(User $actor, IncidentNotification $notification, array $data): IncidentNotificationEvent
    {
        Enterprise::assertEnabled('incidents');

        return DB::transaction(function () use ($actor, $notification, $data): IncidentNotificationEvent {
            $incidentId = IncidentNotification::query()->whereKey($notification->id)->value('incident_id');
            $incident = Incident::query()->lockForUpdate()->findOrFail($incidentId);
            $locked = IncidentNotification::query()->where('incident_id', $incident->id)->lockForUpdate()->findOrFail($notification->id);
            $this->authorize($actor);
            $this->assertGoverned($incident);
            $validated = Validator::make($data, self::decisionRules())->validate();
            if ($locked->events()->count() >= 50) {
                throw ValidationException::withMessages(['notification' => 'A notification record is limited to 50 governed events.']);
            }

            $current = $locked->status;
            if ($current->allowedNext() === []) {
                throw ValidationException::withMessages(['status' => 'Sent, not-required, and cancelled notifications are terminal.']);
            }
            $updates = collect($validated)->only(['framework', 'recipient', 'deadline_at', 'delivery_reference'])->all();
            $next = $current;
            $prospectiveReference = array_key_exists('delivery_reference', $validated)
                ? $validated['delivery_reference'] : $locked->delivery_reference;
            if (array_key_exists('status', $validated)) {
                $next = IncidentNotificationStatus::from($validated['status']);
                if ($next !== $current && ! in_array($next, $current->allowedNext(), true)) {
                    throw ValidationException::withMessages(['status' => 'The requested notification status transition is not allowed.']);
                }
                $updates['status'] = $next;
                if ($next === IncidentNotificationStatus::Sent) {
                    if (blank($prospectiveReference)) {
                        throw ValidationException::withMessages(['delivery_reference' => 'A delivery reference is required to record sent status.']);
                    }
                    $updates['sent_at'] = now();
                }
            }
            $prospectiveDeadline = array_key_exists('deadline_at', $validated) ? $validated['deadline_at'] : $locked->deadline_at;
            if (in_array($next, [IncidentNotificationStatus::Required, IncidentNotificationStatus::Prepared], true)
                && blank($prospectiveDeadline)) {
                throw ValidationException::withMessages(['deadline_at' => 'Required and prepared notification records require a deliberate deadline.']);
            }

            $before = $this->snapshot($locked, $incident);
            $locked->update($updates);
            $locked->refresh();
            $after = $this->snapshot($locked, $incident);
            if ($before === $after) {
                throw ValidationException::withMessages(['status' => 'The notification event must change governed state.']);
            }
            $recordedAt = now();
            $version = ((int) $locked->events()->max('version')) + 1;

            return $this->appendEvent($locked, $actor, $version, 'decision', $before, $after, $validated['rationale'], $recordedAt);
        }, 3);
    }

    /** @return array<string, mixed> */
    public static function registerRules(): array
    {
        return [
            'audience' => ['required', Rule::enum(IncidentNotificationAudience::class)],
            'framework' => 'sometimes|nullable|string|max:255', 'recipient' => 'required|string|max:255',
            'deadline_at' => 'sometimes|nullable|date', 'rationale' => 'required|string|max:30000',
        ];
    }

    /** @return array<string, mixed> */
    public static function decisionRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(IncidentNotificationStatus::class)],
            'framework' => 'sometimes|nullable|string|max:255', 'recipient' => 'sometimes|string|max:255',
            'deadline_at' => 'sometimes|nullable|date', 'delivery_reference' => 'sometimes|nullable|string|max:2000',
            'rationale' => 'required|string|max:30000',
        ];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('Manage Breach Notifications'), 403, 'You cannot manage incident notification decisions.');
    }

    private function assertGoverned(Incident $incident): void
    {
        if ($incident->governed_at === null) {
            throw ValidationException::withMessages(['incident' => 'Legacy incidents cannot enter governed notification history.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(IncidentNotification $notification, Incident $incident): array
    {
        return [
            'id' => $notification->id, 'incident_id' => $notification->incident_id,
            'audience' => $notification->audience->value, 'framework' => $notification->framework,
            'recipient' => $notification->recipient, 'status' => $notification->status->value,
            'deadline_at' => $notification->deadline_at?->toIso8601String(),
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'delivery_reference' => $notification->delivery_reference,
            'governed_at' => $notification->governed_at->toIso8601String(),
            'incident' => [
                'id' => $incident->id, 'number' => $incident->number, 'title' => $incident->title,
                'type' => $incident->type, 'severity' => $incident->severity, 'status' => $incident->status,
                'phase' => $incident->phase->value, 'involves_data' => $incident->involves_data,
                'involves_pii' => $incident->involves_pii, 'is_breach' => $incident->is_breach,
                'detected_at' => $incident->detected_at?->toIso8601String(),
                'lead_id' => $incident->lead_id, 'reporter_id' => $incident->reporter_id,
                'root_cause' => $incident->root_cause, 'business_impact' => $incident->business_impact,
                'closure' => $incident->closure, 'phase_timestamps' => $incident->phase_timestamps,
                'playbook_snapshot' => $incident->playbook_snapshot,
                'governed_at' => $incident->governed_at?->toIso8601String(),
            ],
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function appendEvent(IncidentNotification $notification, User $actor, int $version, string $type, ?array $before, array $after, string $rationale, mixed $recordedAt): IncidentNotificationEvent
    {
        $payload = [
            'incident_id' => $notification->incident_id, 'incident_notification_id' => $notification->id,
            'version' => $version, 'event_type' => $type, 'before_snapshot' => $before,
            'after_snapshot' => $after, 'rationale' => $rationale, 'recorded_by' => $actor->id,
            'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $notification->events()->create($payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
