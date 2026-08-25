<?php

namespace App\ComplianceCases;

use App\Access\FileAccess;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseEvidenceFile;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\User;
use App\Services\GovernedEvidenceSnapshotter;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplianceCaseEvidenceManager
{
    /** @param array{summary:string,evidence_attachment_ids:list<int>} $data */
    public function submit(User $actor, ComplianceCase $case, array $data): ComplianceCaseEvidenceSubmission
    {
        Enterprise::assertEnabled('compliance_cases');
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($actor, $case, $data, &$retainedCopies): ComplianceCaseEvidenceSubmission {
                $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
                $isManager = $actor->can('Manage Compliance Cases');
                $isInvestigator = $actor->can('Investigate Compliance Cases') && $locked->assigned_to === $actor->id;
                abort_unless($isManager || $isInvestigator, 403);
                if ($locked->status === ComplianceCaseStatus::Closed) {
                    throw ValidationException::withMessages(['case' => 'Closed compliance cases cannot receive new evidence.']);
                }
                $events = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
                $submissions = ComplianceCaseEvidenceSubmission::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
                if ($submissions->count() >= 100) {
                    throw ValidationException::withMessages(['case' => 'A compliance case is limited to 100 governed evidence submissions.']);
                }
                $recordedAt = now()->startOfSecond();
                $manifest = app(GovernedEvidenceSnapshotter::class)->snapshot(
                    $data['evidence_attachment_ids'], $actor, 'compliance-cases', Str::uuid()->toString(), $retainedCopies,
                );
                $latestEvent = $events->last();
                $payload = [
                    'compliance_case_id' => $locked->id,
                    'version' => $submissions->count() + 1,
                    'summary' => $data['summary'],
                    'case_snapshot' => $latestEvent?->after_snapshot ?? [],
                    'latest_event_snapshot' => $latestEvent?->attributesToArray() ?? [],
                    'evidence_manifest' => $manifest,
                    'recorded_by' => $actor->id,
                    'actor_snapshot' => $actor->only(['id', 'name', 'email']),
                    'recorded_at' => $recordedAt->toIso8601String(),
                ];
                $submission = ComplianceCaseEvidenceSubmission::query()->create($payload + [
                    'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
                ]);
                foreach ($manifest as $snapshot) {
                    ComplianceCaseEvidenceFile::query()->create($snapshot + [
                        'compliance_case_evidence_submission_id' => $submission->id,
                        'linked_by' => $actor->id,
                        'linked_at' => $recordedAt,
                    ]);
                }

                return $submission->load(['actor:id,name,email', 'evidence.attachment']);
            }, 3);
        } catch (\Throwable $exception) {
            app(GovernedEvidenceSnapshotter::class)->cleanup($retainedCopies);
            throw $exception;
        }
    }

    /** @param Collection<int,ComplianceCaseEvidenceSubmission> $submissions @return Collection<int,ComplianceCaseEvidenceSubmission> */
    public function visibleSubmissions(Collection $submissions, User $actor): Collection
    {
        return $submissions->map(function (ComplianceCaseEvidenceSubmission $submission) use ($actor): ComplianceCaseEvidenceSubmission {
            $visible = clone $submission;
            $visible->setRelation('evidence', $submission->evidence
                ->filter(fn (ComplianceCaseEvidenceFile $evidence): bool => $evidence->attachment !== null
                    && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))
                ->map(function (ComplianceCaseEvidenceFile $evidence): ComplianceCaseEvidenceFile {
                    $projected = clone $evidence;
                    $projected->unsetRelation('attachment');

                    return $projected;
                })->values());

            return $visible;
        });
    }

    /** @return array<string,mixed> */
    public static function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'evidence_attachment_ids' => 'required|array|min:1|max:20',
            'evidence_attachment_ids.*' => 'required|integer|distinct',
            'version' => 'prohibited', 'case_snapshot' => 'prohibited', 'latest_event_snapshot' => 'prohibited',
            'evidence_manifest' => 'prohibited', 'recorded_by' => 'prohibited', 'actor_snapshot' => 'prohibited',
            'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
