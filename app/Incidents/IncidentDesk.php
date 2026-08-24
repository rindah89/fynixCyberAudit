<?php

namespace App\Incidents;

use App\Enums\IncidentPhase;
use App\Enums\IncidentTaskStatus;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\IncidentNumberSequence;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPlaybook;
use App\Models\IncidentTask;
use App\Models\IncidentTaskEvent;
use App\Models\User;
use App\Services\GovernedEvidenceSnapshotter;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IncidentDesk
{
    /**
     * @param  array{title: string, severity?: string, type?: string, detected_at?: mixed}  $data
     */
    public function createFromPlaybook(User $actor, IncidentPlaybook $playbook, array $data): Incident
    {
        Enterprise::assertEnabled('incidents');
        abort_unless($actor->can('create', Incident::class), 403, 'You cannot create incidents.');
        $data = Validator::make($data, [
            'title' => 'required|string|max:255', 'type' => 'nullable|string|max:255',
            'severity' => 'sometimes|in:Low,Medium,High,Critical',
            'detected_at' => 'sometimes|date|before_or_equal:now',
            'involves_data' => 'sometimes|boolean', 'involves_pii' => 'sometimes|boolean', 'is_breach' => 'sometimes|boolean',
        ])->validate();

        return DB::transaction(function () use ($actor, $playbook, $data): Incident {
            $lockedPlaybook = IncidentPlaybook::query()->lockForUpdate()->findOrFail($playbook->id);
            $templates = $lockedPlaybook->tasks()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            if ($templates->count() > 100) {
                throw ValidationException::withMessages(['incident_playbook_id' => 'A governed incident playbook is limited to 100 tasks.']);
            }
            $occurredAt = now();
            $playbookSnapshot = [
                'id' => $lockedPlaybook->id,
                'name' => $lockedPlaybook->name,
                'incident_type' => $lockedPlaybook->incident_type,
                'description' => $lockedPlaybook->description,
                'tasks' => $templates->map(fn ($template): array => [
                    'id' => $template->id,
                    'title' => $template->title,
                    'phase' => $template->phase->value,
                    'priority' => $template->priority,
                    'sort_order' => $template->sort_order,
                ])->all(),
            ];

            $incident = Incident::query()->create([
                'incident_playbook_id' => $lockedPlaybook->id,
                'number' => $this->nextNumber((int) $occurredAt->format('Y')),
                'title' => $data['title'],
                'type' => $data['type'] ?? $lockedPlaybook->incident_type,
                'severity' => $data['severity'] ?? 'Medium',
                'status' => 'Open',
                'phase' => IncidentPhase::Identification,
                'lead_id' => $actor->id,
                'reporter_id' => $actor->id,
                'detected_at' => $data['detected_at'] ?? $occurredAt,
                'involves_data' => $data['involves_data'] ?? false,
                'involves_pii' => $data['involves_pii'] ?? false,
                'is_breach' => $data['is_breach'] ?? false,
                'phase_timestamps' => [IncidentPhase::Identification->value => $occurredAt->toIso8601String()],
                'playbook_snapshot' => $playbookSnapshot,
                'governed_at' => $occurredAt,
            ]);

            foreach ($templates as $template) {
                $task = $incident->tasks()->create([
                    'title' => $template->title, 'phase' => $template->phase,
                    'status' => 'Open', 'priority' => $template->priority ?? 'Medium',
                    'governed_at' => $occurredAt,
                ]);
                $this->appendTaskEvent($incident, $task, $actor, 1, 'seeded', null, $this->taskSnapshot($task, $incident), [], 'Task seeded from governed playbook.', $occurredAt);
            }

            $this->appendTransition($incident, $actor, null, IncidentPhase::Identification, 'Incident created from governed playbook.', $occurredAt);

            return $incident->fresh(['tasks', 'phaseTransitions.actor']);
        }, 3);
    }

    public function advancePhase(User $actor, Incident $incident, IncidentPhase $phase, string $summary = 'Incident response phase advanced.'): Incident
    {
        Enterprise::assertEnabled('incidents');
        $summary = Validator::make(['summary' => $summary], ['summary' => 'required|string|max:10000'])->validate()['summary'];

        return DB::transaction(function () use ($actor, $incident, $phase, $summary): Incident {
            $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless($actor->can('update', $locked), 403, 'You cannot update this incident.');
            abort_if($locked->governed_at === null, 422, 'Legacy incidents cannot enter governed phase history.');
            $current = $locked->phase ?? IncidentPhase::Identification;
            if ($phase->rank() !== $current->rank() + 1) {
                throw new RuntimeException('Incident phase must advance exactly one phase and cannot reverse.');
            }

            $occurredAt = now();
            $timestamps = $locked->phase_timestamps ?? [];
            $timestamps[$phase->value] = $occurredAt->toIso8601String();
            $locked->update(['phase' => $phase, 'phase_timestamps' => $timestamps]);
            $this->appendTransition($locked, $actor, $current, $phase, $summary, $occurredAt);

            return $locked->fresh(['tasks', 'phaseTransitions.actor']);
        }, 3);
    }

    public function storeEvidence(User $actor, Incident $incident, string $contents, string $filename): IncidentEvidence
    {
        $this->assertCanManage($actor);

        $hash = hash('sha256', $contents);
        $relative = 'incidents/'.$incident->id.'/'.uniqid('ev_', true).'-'.$filename;
        Storage::disk('local')->put($relative, $contents);

        return $incident->evidence()->create([
            'type' => 'file',
            'filename' => $filename,
            'path' => $relative,
            'hash' => $hash,
            'phase' => $incident->phase,
            'source' => 'upload',
            'chain_of_custody' => true,
            'uploaded_by' => $actor->id,
        ]);
    }

    /**
     * @param  array{status?: string, assignee_id?: int|null, due_date?: string|null, evidence_attachment_ids?: list<int>, summary: string}  $data
     */
    public function recordTaskEvent(User $actor, IncidentTask $task, array $data): IncidentTaskEvent
    {
        Enterprise::assertEnabled('incidents');
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($actor, $task, $data, $snapshotter, $snapshotBatch, &$retainedCopies): IncidentTaskEvent {
                $incidentId = IncidentTask::query()->whereKey($task->id)->value('incident_id');
                $incident = Incident::query()->lockForUpdate()->findOrFail($incidentId);
                $locked = IncidentTask::query()->where('incident_id', $incident->id)->lockForUpdate()->findOrFail($task->id);
                abort_if($incident->governed_at === null || $locked->governed_at === null, 422, 'Legacy incident tasks cannot enter governed task history.');

                $manager = $actor->can('update', $incident) || $actor->can('Manage Incident Tasks');
                $assignee = $locked->assignee_id === $actor->id;
                abort_unless($manager || $assignee, 403, 'You cannot update this incident task.');
                if (! $manager && (array_key_exists('assignee_id', $data) || array_key_exists('due_date', $data))) {
                    abort(403, 'Task assignees cannot change assignment or due date.');
                }

                $data = Validator::make($data, [
                    'status' => ['sometimes', Rule::enum(IncidentTaskStatus::class)],
                    'assignee_id' => 'sometimes|nullable|integer|exists:users,id',
                    'due_date' => 'sometimes|nullable|date|after_or_equal:today',
                    'evidence_attachment_ids' => 'sometimes|array|max:20',
                    'evidence_attachment_ids.*' => 'integer|distinct',
                    'summary' => 'required|string|max:10000',
                ])->after(function ($validator) use ($data): void {
                    if (! array_key_exists('status', $data) && ! array_key_exists('assignee_id', $data) && ! array_key_exists('due_date', $data)) {
                        $validator->errors()->add('status', 'A status, assignee, or due date change is required.');
                    }
                })->validate();

                if (array_key_exists('assignee_id', $data) && $data['assignee_id'] !== null) {
                    $selectedAssignee = User::query()->lockForUpdate()->find($data['assignee_id']);
                    if ($selectedAssignee === null) {
                        throw ValidationException::withMessages(['assignee_id' => 'The selected assignee must be active.']);
                    }
                }

                abort_if($locked->events()->count() >= 100, 422, 'An incident task is limited to 100 governed events.');
                $before = $this->taskSnapshot($locked, $incident);
                $updates = [];
                $current = IncidentTaskStatus::from($locked->status);
                if ($current->allowedNext() === []) {
                    throw ValidationException::withMessages(['status' => 'Completed or cancelled incident tasks are terminal.']);
                }
                if (array_key_exists('status', $data)) {
                    $next = IncidentTaskStatus::from($data['status']);
                    if ($next !== $current && ! in_array($next, $current->allowedNext(), true)) {
                        throw ValidationException::withMessages(['status' => 'The requested task status transition is not allowed.']);
                    }
                    if ($next !== IncidentTaskStatus::Open && $locked->phase->rank() > $incident->phase->rank()) {
                        throw ValidationException::withMessages(['status' => 'The incident has not reached this task phase.']);
                    }
                    $updates['status'] = $next->value;
                }
                if (array_key_exists('assignee_id', $data)) {
                    $updates['assignee_id'] = $data['assignee_id'];
                }
                if (array_key_exists('due_date', $data)) {
                    $updates['due_date'] = $data['due_date'];
                }
                $evidenceAttachmentIds = $data['evidence_attachment_ids'] ?? [];
                $evidenceManifest = $evidenceAttachmentIds === [] ? [] : $snapshotter->snapshot(
                    $evidenceAttachmentIds, $actor, 'incident-task-event', $snapshotBatch, $retainedCopies,
                );

                $locked->update($updates);
                $locked->refresh();
                $after = $this->taskSnapshot($locked, $incident);
                if ($before === $after) {
                    throw ValidationException::withMessages(['status' => 'The task event must change governed state.']);
                }
                $occurredAt = now();
                $version = ((int) $locked->events()->max('version')) + 1;

                return $this->appendTaskEvent($incident, $locked, $actor, $version, 'updated', $before, $after, $evidenceManifest, $data['summary'], $occurredAt);
            }, 3);
        } catch (\Throwable $exception) {
            $snapshotter->cleanup($retainedCopies);

            throw $exception;
        }
    }

    private function assertCanManage(User $actor): void
    {
        Enterprise::assertEnabled('incidents');

        if ($actor->isSuperAdmin() || $actor->can('Manage Incidents')) {
            return;
        }

        abort(403, 'You cannot manage incidents.');
    }

    private function nextNumber(int $year): string
    {
        IncidentNumberSequence::query()->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = IncidentNumberSequence::query()->lockForUpdate()->findOrFail($year);
        $sequence->update(['last_number' => $sequence->last_number + 1]);

        return sprintf('INC-%d-%04d', $year, $sequence->last_number);
    }

    private function appendTransition(Incident $incident, User $actor, ?IncidentPhase $from, IncidentPhase $to, string $summary, mixed $occurredAt): IncidentPhaseTransition
    {
        $snapshot = [
            'id' => $incident->id, 'number' => $incident->number, 'title' => $incident->title,
            'type' => $incident->type, 'severity' => $incident->severity, 'status' => $incident->status,
            'phase' => $to->value, 'lead_id' => $incident->lead_id, 'reporter_id' => $incident->reporter_id,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'involves_data' => $incident->involves_data, 'involves_pii' => $incident->involves_pii,
            'is_breach' => $incident->is_breach, 'root_cause' => $incident->root_cause,
            'business_impact' => $incident->business_impact, 'closure' => $incident->closure,
            'phase_timestamps' => $incident->phase_timestamps, 'playbook' => $incident->playbook_snapshot,
        ];
        $payload = [
            'incident_id' => $incident->id, 'from_phase' => $from?->value, 'to_phase' => $to->value,
            'summary' => $summary, 'incident_snapshot' => $snapshot,
            'transitioned_by' => $actor->id, 'transitioned_at' => $occurredAt->toIso8601String(),
        ];

        return $incident->phaseTransitions()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    /** @return array<string, mixed> */
    private function taskSnapshot(IncidentTask $task, Incident $incident): array
    {
        $assignee = $task->assignee_id === null ? null : User::withTrashed()->find($task->assignee_id);

        return [
            'id' => $task->id, 'incident_id' => $task->incident_id, 'title' => $task->title,
            'phase' => $task->phase->value, 'status' => $task->status, 'priority' => $task->priority,
            'assignee' => $assignee === null ? null : ['id' => $assignee->id, 'name' => $assignee->name],
            'due_date' => $task->due_date?->toDateString(), 'governed_at' => $task->governed_at?->toIso8601String(),
            'incident' => [
                'id' => $incident->id, 'number' => $incident->number,
                'phase' => $incident->phase->value, 'governed_at' => $incident->governed_at?->toIso8601String(),
            ],
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after @param list<array<string, mixed>> $evidenceManifest */
    private function appendTaskEvent(Incident $incident, IncidentTask $task, User $actor, int $version, string $eventType, ?array $before, array $after, array $evidenceManifest, string $summary, mixed $occurredAt): IncidentTaskEvent
    {
        $payload = [
            'incident_id' => $incident->id, 'incident_task_id' => $task->id, 'version' => $version,
            'event_type' => $eventType, 'from_status' => $before['status'] ?? null, 'to_status' => $after['status'],
            'before_snapshot' => $before, 'after_snapshot' => $after, 'evidence_manifest' => $evidenceManifest, 'summary' => $summary,
            'recorded_by' => $actor->id, 'recorded_at' => $occurredAt->toIso8601String(),
        ];

        $event = $task->events()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        foreach ($evidenceManifest as $manifest) {
            $event->evidence()->create($manifest + ['linked_by' => $actor->id, 'linked_at' => $occurredAt]);
        }

        return $event;
    }
}
