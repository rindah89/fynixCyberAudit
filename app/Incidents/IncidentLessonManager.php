<?php

namespace App\Incidents;

use App\Enums\IncidentLessonArea;
use App\Enums\IncidentLessonStatus;
use App\Enums\IncidentPhase;
use App\Models\Incident;
use App\Models\IncidentLesson;
use App\Models\IncidentLessonEvent;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentLessonManager
{
    /** @param array{area: string, observation: string, recommendation: string, owner_id: int, target_date?: string|null, rationale: string} $data */
    public function register(User $actor, Incident $incident, array $data): IncidentLesson
    {
        Enterprise::assertEnabled('incidents');

        return DB::transaction(function () use ($actor, $incident, $data): IncidentLesson {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->authorizeManager($actor, $lockedIncident);
            $validated = Validator::make($data, self::registerRules())->validate();
            $this->assertEligibleIncident($lockedIncident);
            if ($lockedIncident->lessons()->count() >= 100) {
                throw ValidationException::withMessages(['incident' => 'An incident is limited to 100 governed lessons.']);
            }
            $owner = User::query()->lockForUpdate()->find($validated['owner_id']);
            if ($owner === null) {
                throw ValidationException::withMessages(['owner_id' => 'The lesson owner must be an active user.']);
            }

            $recordedAt = now();
            $lesson = $lockedIncident->lessons()->create([
                'area' => $validated['area'], 'observation' => $validated['observation'],
                'recommendation' => $validated['recommendation'], 'owner_id' => $owner->id,
                'target_date' => $validated['target_date'] ?? null, 'status' => IncidentLessonStatus::Proposed,
                'governed_at' => $recordedAt,
            ]);
            $after = $this->snapshot($lesson, $lockedIncident, $owner);
            $this->appendEvent($lesson, $actor, 1, 'registered', null, $after, $validated['rationale'], $recordedAt);

            return $lesson->load(['owner:id,name,email', 'events.actor:id,name']);
        }, 3);
    }

    /** @param array{status?: string, area?: string, observation?: string, recommendation?: string, owner_id?: int, target_date?: string|null, rationale: string} $data */
    public function recordProgress(User $actor, IncidentLesson $lesson, array $data): IncidentLessonEvent
    {
        Enterprise::assertEnabled('incidents');

        return DB::transaction(function () use ($actor, $lesson, $data): IncidentLessonEvent {
            $incidentId = IncidentLesson::query()->whereKey($lesson->id)->value('incident_id');
            $incident = Incident::query()->lockForUpdate()->findOrFail($incidentId);
            $locked = IncidentLesson::query()->where('incident_id', $incident->id)->lockForUpdate()->findOrFail($lesson->id);
            $manager = $actor->can('update', $incident) || $actor->can('Manage Incidents');
            $owner = $locked->owner_id === $actor->id;
            abort_unless($manager || $owner, 403, 'You cannot update this incident lesson.');
            $validated = Validator::make($data, self::progressRules())->validate();
            if (! $manager && collect(['area', 'observation', 'recommendation', 'owner_id', 'target_date'])->contains(fn (string $field): bool => array_key_exists($field, $validated))) {
                abort(403, 'Lesson owners may update status only.');
            }
            $this->assertEligibleIncident($incident);
            if ($locked->events()->count() >= 50) {
                throw ValidationException::withMessages(['lesson' => 'An incident lesson is limited to 50 governed events.']);
            }
            if ($locked->status->allowedNext() === []) {
                throw ValidationException::withMessages(['status' => 'Implemented and closed-without-action lessons are terminal.']);
            }

            $updates = collect($validated)->only(['area', 'observation', 'recommendation', 'owner_id', 'target_date'])->all();
            if (array_key_exists('status', $validated)) {
                $next = IncidentLessonStatus::from($validated['status']);
                if ($next !== $locked->status && ! in_array($next, $locked->status->allowedNext(), true)) {
                    throw ValidationException::withMessages(['status' => 'The requested lesson status transition is not allowed.']);
                }
                $updates['status'] = $next;
            }
            $ownerId = (int) ($validated['owner_id'] ?? $locked->owner_id);
            $selectedOwner = User::query()->lockForUpdate()->find($ownerId);
            if ($selectedOwner === null) {
                throw ValidationException::withMessages(['owner_id' => 'The lesson owner must be an active user.']);
            }

            $before = $this->snapshot($locked, $incident, $locked->owner()->withTrashed()->firstOrFail());
            $locked->update($updates);
            $locked->refresh();
            $after = $this->snapshot($locked, $incident, $selectedOwner);
            if ($before === $after) {
                throw ValidationException::withMessages(['status' => 'The lesson event must change governed state.']);
            }
            $recordedAt = now();
            $version = ((int) $locked->events()->max('version')) + 1;

            return $this->appendEvent($locked, $actor, $version, 'progress', $before, $after, $validated['rationale'], $recordedAt);
        }, 3);
    }

    /** @return array<string, mixed> */
    public static function registerRules(): array
    {
        return [
            'area' => ['required', Rule::enum(IncidentLessonArea::class)],
            'observation' => 'required|string|max:30000', 'recommendation' => 'required|string|max:30000',
            'owner_id' => 'required|integer|exists:users,id', 'target_date' => 'sometimes|nullable|date|after_or_equal:today',
            'rationale' => 'required|string|max:30000',
        ];
    }

    /** @return array<string, mixed> */
    public static function progressRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(IncidentLessonStatus::class)],
            'area' => ['sometimes', Rule::enum(IncidentLessonArea::class)],
            'observation' => 'sometimes|string|max:30000', 'recommendation' => 'sometimes|string|max:30000',
            'owner_id' => 'sometimes|integer|exists:users,id', 'target_date' => 'sometimes|nullable|date|after_or_equal:today',
            'rationale' => 'required|string|max:30000',
        ];
    }

    private function authorizeManager(User $actor, Incident $incident): void
    {
        abort_unless($actor->can('update', $incident) || $actor->can('Manage Incidents'), 403, 'You cannot register incident lessons.');
    }

    private function assertEligibleIncident(Incident $incident): void
    {
        if ($incident->governed_at === null || $incident->phase !== IncidentPhase::LessonsLearned) {
            throw ValidationException::withMessages(['incident' => 'Governed lessons require an incident in the Lessons Learned phase.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(IncidentLesson $lesson, Incident $incident, User $owner): array
    {
        return [
            'id' => $lesson->id, 'incident_id' => $lesson->incident_id, 'area' => $lesson->area->value,
            'observation' => $lesson->observation, 'recommendation' => $lesson->recommendation,
            'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            'target_date' => $lesson->target_date?->toDateString(), 'status' => $lesson->status->value,
            'governed_at' => $lesson->governed_at->toIso8601String(),
            'incident' => [
                'id' => $incident->id, 'number' => $incident->number, 'title' => $incident->title,
                'type' => $incident->type, 'severity' => $incident->severity, 'status' => $incident->status,
                'phase' => $incident->phase->value, 'lead_id' => $incident->lead_id, 'reporter_id' => $incident->reporter_id,
                'detected_at' => $incident->detected_at?->toIso8601String(), 'involves_data' => $incident->involves_data,
                'involves_pii' => $incident->involves_pii, 'is_breach' => $incident->is_breach,
                'root_cause' => $incident->root_cause, 'business_impact' => $incident->business_impact,
                'closure' => $incident->closure, 'phase_timestamps' => $incident->phase_timestamps,
                'playbook_snapshot' => $incident->playbook_snapshot,
                'governed_at' => $incident->governed_at?->toIso8601String(),
            ],
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function appendEvent(IncidentLesson $lesson, User $actor, int $version, string $type, ?array $before, array $after, string $rationale, mixed $recordedAt): IncidentLessonEvent
    {
        $payload = [
            'incident_id' => $lesson->incident_id, 'incident_lesson_id' => $lesson->id, 'version' => $version,
            'event_type' => $type, 'before_snapshot' => $before, 'after_snapshot' => $after,
            'rationale' => $rationale, 'recorded_by' => $actor->id, 'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $lesson->events()->create($payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
