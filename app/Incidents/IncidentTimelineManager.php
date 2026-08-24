<?php

namespace App\Incidents;

use App\Enums\IncidentTimelineEntryType;
use App\Enums\IncidentTimelineVisibility;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentTimelineManager
{
    /** @param array{entry_type:string,visibility:string,occurred_at:mixed,summary:string,details?:string|null,pinned?:bool} $data */
    public function record(User $actor, Incident $incident, array $data): IncidentTimelineEntry
    {
        Enterprise::assertEnabled('incidents');
        $data = Validator::make($data, [
            'entry_type' => ['required', Rule::enum(IncidentTimelineEntryType::class)],
            'visibility' => ['required', Rule::enum(IncidentTimelineVisibility::class)],
            'occurred_at' => 'required|date|before_or_equal:now',
            'summary' => 'required|string|max:10000', 'details' => 'nullable|string|max:30000', 'pinned' => 'sometimes|boolean',
        ])->validate();

        return DB::transaction(function () use ($actor, $incident, $data): IncidentTimelineEntry {
            $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless($actor->can('update', $locked), 403, 'You cannot record this incident timeline.');
            abort_if($locked->governed_at === null, 422, 'Legacy incidents cannot enter governed timeline history.');
            if ($locked->timelineEntries()->count() >= 500) {
                throw ValidationException::withMessages(['incident' => 'A governed incident is limited to 500 timeline entries.']);
            }
            $recordedAt = now();
            $occurredAt = Carbon::parse($data['occurred_at']);
            $payload = [
                'incident_id' => $locked->id,
                'version' => ((int) $locked->timelineEntries()->max('version')) + 1,
                'entry_type' => IncidentTimelineEntryType::from($data['entry_type'])->value,
                'visibility' => IncidentTimelineVisibility::from($data['visibility'])->value,
                'occurred_at' => $occurredAt->toIso8601String(),
                'summary' => $data['summary'], 'details' => $data['details'] ?? null, 'pinned' => $data['pinned'] ?? false,
                'incident_snapshot' => $this->snapshot($locked),
                'recorded_by' => $actor->id, 'recorded_at' => $recordedAt->toIso8601String(),
            ];

            return $locked->timelineEntries()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);
        }, 3);
    }

    /** @return array<string,mixed> */
    private function snapshot(Incident $incident): array
    {
        return [
            'id' => $incident->id, 'number' => $incident->number, 'title' => $incident->title, 'type' => $incident->type,
            'severity' => $incident->severity, 'status' => $incident->status, 'phase' => $incident->phase->value,
            'lead_id' => $incident->lead_id, 'reporter_id' => $incident->reporter_id,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'involves_data' => $incident->involves_data, 'involves_pii' => $incident->involves_pii, 'is_breach' => $incident->is_breach,
            'root_cause' => $incident->root_cause, 'business_impact' => $incident->business_impact, 'closure' => $incident->closure,
            'phase_timestamps' => $incident->phase_timestamps, 'playbook_snapshot' => $incident->playbook_snapshot,
            'governed_at' => $incident->governed_at?->toIso8601String(),
        ];
    }
}
