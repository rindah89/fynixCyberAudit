<?php

namespace Database\Factories;

use App\Enums\IncidentPhase;
use App\Models\Incident;
use App\Models\IncidentFinalReport;
use App\Models\IncidentPlaybook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IncidentFinalReportFactory extends Factory
{
    protected $model = IncidentFinalReport::class;

    public function definition(): array
    {
        $generatedAt = now()->startOfSecond();

        return [
            'incident_id' => function (): int {
                $lead = User::factory()->create();
                $playbook = IncidentPlaybook::factory()->create();

                return Incident::query()->create([
                    'number' => 'INC-FAC-'.Str::upper(Str::random(12)), 'incident_playbook_id' => $playbook->id,
                    'title' => fake()->sentence(), 'severity' => 'Medium', 'status' => 'Open',
                    'phase' => IncidentPhase::LessonsLearned, 'lead_id' => $lead->id, 'reporter_id' => $lead->id,
                    'detected_at' => now(), 'playbook_snapshot' => ['id' => $playbook->id, 'name' => $playbook->name],
                    'governed_at' => now(),
                ])->id;
            },
            'version' => 1,
            'report_snapshot' => [
                'executive_summary' => 'Factory final incident report.', 'conclusions' => 'Factory conclusion.',
                'incident' => [], 'phase_transitions' => [], 'tasks' => [], 'evidence_manifest' => [],
                'notifications' => [], 'affected_entities' => [], 'auditor_timeline' => [], 'lessons' => [],
                'source_fingerprints' => [
                    'phase_transitions' => [], 'task_latest_events' => [], 'notification_latest_events' => [],
                    'lesson_latest_events' => [], 'affected_entities' => [], 'auditor_timeline' => [],
                ],
            ],
            'evidence_attachment_ids' => [],
            'generated_by' => fn (array $attributes): int => (int) Incident::query()->findOrFail($attributes['incident_id'])->lead_id,
            'generated_at' => $generatedAt,
            'report_disk' => 'private', 'report_path' => fn (): string => 'incident-final-reports/'.Str::uuid().'.pdf',
            'report_size' => 1, 'report_sha256' => hash('sha256', 'x'),
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'incident_id' => $attributes['incident_id'], 'version' => $attributes['version'],
                'report_snapshot' => $attributes['report_snapshot'], 'evidence_attachment_ids' => $attributes['evidence_attachment_ids'],
                'generated_by' => $attributes['generated_by'], 'generated_at' => $attributes['generated_at']->toIso8601String(),
                'report_disk' => $attributes['report_disk'], 'report_path' => $attributes['report_path'],
                'report_size' => $attributes['report_size'], 'report_sha256' => $attributes['report_sha256'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }
}
