<?php

namespace App\Incidents;

use App\Access\FileAccess;
use App\Enums\IncidentPhase;
use App\Enums\IncidentTimelineVisibility;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Incident;
use App\Models\IncidentFinalReport;
use App\Models\IncidentLessonEvent;
use App\Models\IncidentNotificationEvent;
use App\Models\IncidentPhaseTransition;
use App\Models\IncidentPhaseTransitionEvidence;
use App\Models\IncidentTask;
use App\Models\IncidentTaskEvent;
use App\Models\IncidentTaskEventEvidence;
use App\Models\User;
use App\Support\Enterprise;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentFinalReportManager
{
    public const MAX_SNAPSHOT_BYTES = 5_000_000;

    public const MAX_REPORT_BYTES = 10 * 1024 * 1024;

    /** @param array{executive_summary:string,conclusions:string} $data */
    public function generate(User $actor, Incident $incident, array $data): IncidentFinalReport
    {
        Enterprise::assertEnabled('incidents');
        $data = Validator::make($data, [
            'executive_summary' => 'required|string|max:30000', 'conclusions' => 'required|string|max:30000',
        ])->validate();
        $batch = Str::uuid()->toString();
        $disk = setting('storage.driver', 'private');
        $path = "incident-final-reports/{$batch}.pdf";
        $written = false;

        try {
            return DB::transaction(function () use ($actor, $incident, $data, $disk, $path, &$written): IncidentFinalReport {
                $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);
                abort_unless($actor->can('update', $locked) && $actor->can('Manage Incident Evidence'), 403, 'You cannot generate this incident report.');
                abort_if($locked->governed_at === null, 422, 'Legacy incidents cannot generate governed final reports.');
                if ($locked->phase !== IncidentPhase::LessonsLearned) {
                    throw ValidationException::withMessages(['incident' => 'The incident must reach Lessons Learned before final reporting.']);
                }
                if ($locked->finalReports()->count() >= 20) {
                    throw ValidationException::withMessages(['incident' => 'A governed incident is limited to 20 final-report versions.']);
                }

                $snapshot = $this->lockAndSnapshot($actor, $locked, $data);
                $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (strlen($encoded) > self::MAX_SNAPSHOT_BYTES) {
                    throw ValidationException::withMessages(['incident' => 'The governed final-report snapshot exceeds 5,000,000 serialized bytes.']);
                }
                $bytes = Pdf::loadView('reports.governed-incident-final', ['report' => $snapshot])->output();
                if (strlen($bytes) > self::MAX_REPORT_BYTES) {
                    throw ValidationException::withMessages(['incident' => 'The governed final-report PDF exceeds 10 MiB.']);
                }
                $written = true;
                if (! Storage::disk($disk)->put($path, $bytes)) {
                    throw ValidationException::withMessages(['incident' => 'The governed final-report PDF could not be retained.']);
                }
                $generatedAt = now();
                $version = ((int) $locked->finalReports()->max('version')) + 1;
                $sha = hash('sha256', $bytes);
                $payload = [
                    'incident_id' => $locked->id, 'version' => $version, 'report_snapshot' => $snapshot,
                    'evidence_attachment_ids' => collect($snapshot['evidence_manifest'])->pluck('file_attachment_id')->unique()->sort()->values()->all(),
                    'generated_by' => $actor->id, 'generated_at' => $generatedAt->toIso8601String(),
                    'report_disk' => $disk, 'report_path' => $path, 'report_size' => strlen($bytes), 'report_sha256' => $sha,
                ];

                return $locked->finalReports()->create($payload + [
                    'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            if ($written) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }
    }

    /** @param array{executive_summary:string,conclusions:string} $data @return array<string,mixed> */
    private function lockAndSnapshot(User $actor, Incident $incident, array $data): array
    {
        $transitions = IncidentPhaseTransition::query()->where('incident_id', $incident->id)->orderBy('id')->lockForUpdate()->get();
        $tasks = IncidentTask::query()->where('incident_id', $incident->id)->orderBy('id')->lockForUpdate()->get();
        $allEvents = IncidentTaskEvent::query()->whereIn('incident_task_id', $tasks->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $latestEvents = $allEvents->groupBy('incident_task_id')->map(fn ($events) => $events->last());
        $phaseEvidence = IncidentPhaseTransitionEvidence::query()->whereIn('incident_phase_transition_id', $transitions->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $taskEvidenceQuery = IncidentTaskEventEvidence::query()->whereIn('incident_task_event_id', $allEvents->pluck('id'));
        if (($phaseEvidence->count() + $taskEvidenceQuery->count()) > 5000) {
            throw ValidationException::withMessages(['incident' => 'A governed final report is limited to 5,000 retained evidence-manifest rows.']);
        }
        $taskEvidence = $taskEvidenceQuery->orderBy('id')->lockForUpdate()->get();
        $attachments = $this->lockAuthorizedAttachments($actor, $phaseEvidence->pluck('file_attachment_id')->merge($taskEvidence->pluck('file_attachment_id'))->unique()->sort()->values()->all());
        $notifications = $incident->notifications()->orderBy('id')->lockForUpdate()->get();
        $notificationEvents = IncidentNotificationEvent::query()->whereIn('incident_notification_id', $notifications->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        $latestNotificationEvents = $notificationEvents->groupBy('incident_notification_id')->map(fn ($events) => $events->last());
        $lessons = $incident->lessons()->orderBy('id')->lockForUpdate()->get();
        $lessonEvents = IncidentLessonEvent::query()->whereIn('incident_lesson_id', $lessons->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        $latestLessonEvents = $lessonEvents->groupBy('incident_lesson_id')->map(fn ($events) => $events->last());
        $affected = $incident->affectedEntities()->reorder()->orderBy('id')->lockForUpdate()->get();
        $timeline = $incident->timelineEntries()->reorder()->where('visibility', IncidentTimelineVisibility::Auditor->value)
            ->orderBy('occurred_at')->orderBy('version')->lockForUpdate()->get();

        $manifest = $phaseEvidence->map(fn ($evidence): array => $this->evidenceSnapshot($evidence, 'phase_transition', $evidence->incident_phase_transition_id))
            ->merge($taskEvidence->map(fn ($evidence): array => $this->evidenceSnapshot($evidence, 'task_event', $evidence->incident_task_event_id)))
            ->sortBy(['context_type', 'context_id', 'file_attachment_id'])->values()->all();

        return [
            'executive_summary' => $data['executive_summary'], 'conclusions' => $data['conclusions'],
            'incident' => $this->incidentSnapshot($incident),
            'phase_transitions' => $transitions->map(fn ($row): array => $row->only(['id', 'from_phase', 'to_phase', 'summary', 'incident_snapshot', 'transitioned_by', 'transitioned_at', 'fingerprint']))->all(),
            'tasks' => $tasks->map(function ($task) use ($latestEvents): array {
                $latest = $latestEvents->get($task->id);

                return $task->only(['id', 'title', 'phase', 'status', 'priority', 'assignee_id', 'due_date', 'governed_at']) + [
                    'latest_event_version' => $latest?->version, 'latest_event_fingerprint' => $latest?->fingerprint,
                ];
            })->all(),
            'evidence_manifest' => $manifest,
            'notifications' => $notifications->map(function ($row) use ($latestNotificationEvents): array {
                $latest = $latestNotificationEvents->get($row->id);

                return $row->only(['id', 'audience', 'framework', 'recipient', 'deadline_at', 'status', 'delivery_reference', 'sent_at', 'governed_at']) + [
                    'latest_event_version' => $latest?->version, 'latest_event_fingerprint' => $latest?->fingerprint,
                ];
            })->all(),
            'affected_entities' => $affected->map(fn ($row): array => $row->only(['id', 'entity_type', 'entity_id_snapshot', 'entity_snapshot', 'impact_summary', 'control_failure_note', 'linked_by', 'linked_at', 'fingerprint']))->all(),
            'auditor_timeline' => $timeline->map(fn ($row): array => $row->only(['id', 'version', 'entry_type', 'occurred_at', 'summary', 'details', 'pinned', 'recorded_by', 'recorded_at', 'fingerprint']))->all(),
            'lessons' => $lessons->map(function ($row) use ($latestLessonEvents): array {
                $latest = $latestLessonEvents->get($row->id);

                return $row->only(['id', 'area', 'observation', 'recommendation', 'owner_id', 'target_date', 'status', 'governed_at']) + [
                    'latest_event_version' => $latest?->version, 'latest_event_fingerprint' => $latest?->fingerprint,
                ];
            })->all(),
            'source_fingerprints' => [
                'phase_transitions' => $transitions->pluck('fingerprint')->all(),
                'task_latest_events' => $latestEvents->pluck('fingerprint')->all(),
                'notification_latest_events' => $latestNotificationEvents->pluck('fingerprint')->all(),
                'lesson_latest_events' => $latestLessonEvents->pluck('fingerprint')->all(),
                'affected_entities' => $affected->pluck('fingerprint')->all(),
                'auditor_timeline' => $timeline->pluck('fingerprint')->all(),
            ],
        ];
    }

    /** @param list<int> $ids */
    private function lockAuthorizedAttachments(User $actor, array $ids)
    {
        if ($ids === []) {
            return collect();
        }
        $attachments = FileAttachment::query()->whereKey($ids)->lockForUpdate()->get()->keyBy('id');
        $responses = DataRequestResponse::query()->whereKey($attachments->pluck('data_request_response_id')->filter()->unique())->lockForUpdate()->get()->keyBy('id');
        $requests = DataRequest::query()->whereKey($responses->pluck('data_request_id')->unique())->lockForUpdate()->get()->keyBy('id');
        $audits = Audit::query()->whereKey($requests->pluck('audit_id')->unique())->lockForUpdate()->get()->keyBy('id');
        DB::table('audit_user')->whereIn('audit_id', $audits->keys())->orderBy('audit_id')->orderBy('user_id')->lockForUpdate()->get();
        $audits->load('members');
        foreach ($ids as $id) {
            $attachment = $attachments->get($id);
            $response = $attachment ? $responses->get($attachment->data_request_response_id) : null;
            $request = $response ? $requests->get($response->data_request_id) : null;
            $audit = $request ? $audits->get($request->audit_id) : null;
            if (! $attachment || ! $response || ! $request || ! $audit) {
                throw ValidationException::withMessages(['incident' => 'Every report evidence reference must retain complete accepted provenance.']);
            }
            $request->setRelation('audit', $audit);
            $response->setRelation('dataRequest', $request);
            $attachment->setRelation('dataRequestResponse', $response);
            $attachment->setRelation('audit', $audit);
            if (! app(FileAccess::class)->canDownloadFileAttachment($actor, $attachment)) {
                throw ValidationException::withMessages(['incident' => 'You must currently access every evidence file included in the report.']);
            }
        }

        return $attachments;
    }

    /** @return array<string,mixed> */
    private function evidenceSnapshot($evidence, string $contextType, int $contextId): array
    {
        return ['context_type' => $contextType, 'context_id' => $contextId] + $evidence->only([
            'file_attachment_id', 'data_request_response_id_snapshot', 'response_status_snapshot', 'data_request_id_snapshot',
            'audit_id_snapshot', 'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot', 'file_size_snapshot', 'sha256', 'linked_by', 'linked_at',
        ]);
    }

    /** @return array<string,mixed> */
    private function incidentSnapshot(Incident $incident): array
    {
        return $incident->only(['id', 'number', 'title', 'type', 'severity', 'status', 'phase', 'lead_id', 'reporter_id', 'detected_at',
            'involves_data', 'involves_pii', 'is_breach', 'root_cause', 'business_impact', 'closure', 'phase_timestamps', 'playbook_snapshot', 'governed_at']);
    }
}
