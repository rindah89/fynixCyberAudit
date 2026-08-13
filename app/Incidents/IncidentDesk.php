<?php

namespace App\Incidents;

use App\Enums\IncidentPhase;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\IncidentPlaybook;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IncidentDesk
{
    /**
     * @param  array{title: string, severity?: string, type?: string, detected_at?: mixed}  $data
     */
    public function createFromPlaybook(User $actor, IncidentPlaybook $playbook, array $data): Incident
    {
        $this->assertCanManage($actor);

        $incident = Incident::query()->create([
            'number' => $this->nextNumber(),
            'title' => $data['title'],
            'type' => $data['type'] ?? $playbook->incident_type,
            'severity' => $data['severity'] ?? 'Medium',
            'status' => 'Open',
            'phase' => IncidentPhase::Identification,
            'lead_id' => $actor->id,
            'reporter_id' => $actor->id,
            'detected_at' => $data['detected_at'] ?? now(),
            'phase_timestamps' => [
                IncidentPhase::Identification->value => now()->toIso8601String(),
            ],
        ]);

        foreach ($playbook->tasks as $template) {
            $incident->tasks()->create([
                'title' => $template->title,
                'phase' => $template->phase,
                'status' => 'Open',
                'priority' => $template->priority ?? 'Medium',
            ]);
        }

        return $incident->fresh(['tasks']);
    }

    public function advancePhase(User $actor, Incident $incident, IncidentPhase $phase): Incident
    {
        $this->assertCanManage($actor);

        $current = $incident->phase ?? IncidentPhase::Identification;
        if ($phase->rank() <= $current->rank()) {
            throw new RuntimeException('Incident phase cannot reverse.');
        }

        $timestamps = $incident->phase_timestamps ?? [];
        $timestamps[$phase->value] = now()->toIso8601String();

        $incident->update([
            'phase' => $phase,
            'phase_timestamps' => $timestamps,
        ]);

        return $incident->fresh();
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

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'INC-'.$year.'-';
        $last = Incident::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $n = 1;
        if (is_string($last) && preg_match('/INC-\d{4}-(\d+)/', $last, $matches)) {
            $n = ((int) $matches[1]) + 1;
        }

        return sprintf('INC-%s-%04d', $year, $n);
    }
}
