<?php

namespace App\Incidents;

use App\Enums\IncidentPhase;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\IncidentNumberSequence;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPlaybook;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
                $incident->tasks()->create([
                    'title' => $template->title, 'phase' => $template->phase,
                    'status' => 'Open', 'priority' => $template->priority ?? 'Medium',
                ]);
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
}
