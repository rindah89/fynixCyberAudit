<?php

namespace App\ComplianceCases;

use App\Access\FileAccess;
use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\ComplianceCaseAccessGrantRevocation;
use App\Models\ComplianceCaseArchiveManifest;
use App\Models\ComplianceCaseArchiveReview;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseCommunicationDecision;
use App\Models\ComplianceCaseConflictDecision;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\ComplianceCaseDispositionReview;
use App\Models\ComplianceCaseEvidenceFile;
use App\Models\ComplianceCaseMilestone;
use App\Models\ComplianceCaseMilestoneDelivery;
use App\Models\ComplianceCaseMilestoneEvent;
use App\Models\ComplianceCaseReopenProposal;
use App\Models\ComplianceCaseReopenReview;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseArchiveManager
{
    /** @param array{summary:string} $data */
    public function generate(User $actor, ComplianceCase $case, array $data): ComplianceCaseArchiveManifest
    {
        Enterprise::assertEnabled('compliance_cases');
        $disk = setting('storage.driver', 'private');
        $path = 'compliance-case-archives/'.Str::uuid().'.zip';
        $written = false;

        try {
            return DB::transaction(function () use ($actor, $case, $data, $disk, $path, &$written): ComplianceCaseArchiveManifest {
                $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
                abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
                app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
                Validator::make($data, ['summary' => 'required|string|max:30000'])->validate();
                if ($locked->status !== ComplianceCaseStatus::Closed) {
                    throw ValidationException::withMessages(['case' => 'Archive manifests require a closed case.']);
                }
                $packages = ComplianceCaseClosureReport::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
                $package = $packages->last();
                if ($package === null) {
                    throw ValidationException::withMessages(['package' => 'A retained closure package is required.']);
                }
                $packageReview = ComplianceCaseClosureReportReview::query()
                    ->where('compliance_case_closure_report_id', $package->id)->lockForUpdate()->first();
                if ($packageReview?->decision !== ComplianceCaseClosureReportReviewDecision::Approved) {
                    throw ValidationException::withMessages(['package' => 'Archive manifests require the latest independently approved closure package.']);
                }
                $existing = ComplianceCaseArchiveManifest::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
                if ($existing->count() >= 20) {
                    throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 archive manifests.']);
                }
                $sources = $this->collectSources($locked, $package, $packageReview, trim($data['summary']));
                $bytes = $this->buildArchive($locked, $package, $sources);
                $written = true;
                if (! app(FileAccess::class)->putPrivate($disk, $path, $bytes)) {
                    throw ValidationException::withMessages(['case' => 'The archive could not be retained.']);
                }
                $generatedAt = now()->startOfSecond();
                $archive = new ComplianceCaseArchiveManifest([
                    'compliance_case_id' => $locked->id, 'compliance_case_closure_report_id' => $package->id,
                    'version' => $existing->count() + 1, 'source_fingerprints' => $sources,
                    'archive_disk' => $disk, 'archive_path' => $path, 'archive_size' => strlen($bytes),
                    'archive_sha256' => hash('sha256', $bytes), 'schema_version' => 'archive/v1',
                    'generated_by' => $actor->id, 'generator_snapshot' => $actor->only(['id', 'name', 'email']),
                    'generated_at' => $generatedAt,
                ]);
                $archive->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($archive)));
                $archive->save();

                return $archive->load('review');
            }, 3);
        } catch (\Throwable $exception) {
            if ($written) {
                try {
                    app(FileAccess::class)->deletePrivate($disk, $path);
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $exception;
        }
    }

    /** @param array{decision:string,summary:string} $data */
    public function review(User $actor, ComplianceCaseArchiveManifest $archive, array $data): ComplianceCaseArchiveReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $archive, $data): ComplianceCaseArchiveReview {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($archive->compliance_case_id);
            $locked = ComplianceCaseArchiveManifest::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($archive->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            abort_if($actor->id === $locked->generated_by, 403, 'The archive generator cannot review the archive.');
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $data = Validator::make($data, ['decision' => 'required|in:approved,rejected', 'summary' => 'required|string|max:30000'])->validate();
            $latest = ComplianceCaseArchiveManifest::query()->where('compliance_case_id', $case->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($latest->id !== $locked->id) {
                throw ValidationException::withMessages(['archive' => 'Only the latest archive manifest can be reviewed.']);
            }
            if ($locked->review()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['archive' => 'This archive already has a terminal review.']);
            }
            app(FileAccess::class)->verifiedContents(
                $locked->archive_disk, $locked->archive_path, $locked->archive_size, $locked->archive_sha256,
                75 * 1024 * 1024, 'The retained archive could not be verified.',
                'The retained archive no longer matches its governed fingerprint.',
            );
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseArchiveReview([
                'compliance_case_archive_manifest_id' => $locked->id, 'decision' => $data['decision'],
                'summary' => trim($data['summary']), 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'manifest_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->payload($locked),
                'reviewed_at' => $reviewedAt,
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();

            return $review;
        }, 3);
    }

    public function download(User $actor, ComplianceCaseArchiveManifest $archive): StreamedResponse
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $archive->complianceCase), 403);
        abort_unless($archive->review?->decision === 'approved', 403, 'Archive download requires independent approval.');
        $bytes = app(FileAccess::class)->verifiedContents(
            $archive->archive_disk, $archive->archive_path, $archive->archive_size, $archive->archive_sha256,
            75 * 1024 * 1024, 'The retained archive could not be verified.',
            'The retained archive no longer matches its governed fingerprint.',
        );

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'Compliance-Case-Archive-'.$archive->complianceCase->number.'-v'.$archive->version.'.zip', ['Content-Type' => 'application/zip']);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->archiveManifests()->with(['review.reviewer:id,name,email', 'generator:id,name,email'])->paginate($perPage);
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseArchiveManifest $archive): array
    {
        return [
            'compliance_case_id' => $archive->compliance_case_id,
            'compliance_case_closure_report_id' => $archive->compliance_case_closure_report_id,
            'version' => $archive->version, 'source_fingerprints' => $archive->source_fingerprints,
            'archive_disk' => $archive->archive_disk, 'archive_path' => $archive->archive_path,
            'archive_size' => $archive->archive_size, 'archive_sha256' => $archive->archive_sha256,
            'schema_version' => $archive->schema_version, 'generated_by' => $archive->generated_by,
            'generator_snapshot' => $archive->generator_snapshot, 'generated_at' => $archive->generated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function reviewPayload(ComplianceCaseArchiveReview $review): array
    {
        return [
            'compliance_case_archive_manifest_id' => $review->compliance_case_archive_manifest_id,
            'decision' => $review->decision, 'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot, 'manifest_snapshot' => $review->manifest_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function collectSources(
        ComplianceCase $case,
        ComplianceCaseClosureReport $package,
        ComplianceCaseClosureReportReview $packageReview,
        string $summary,
    ): array {
        $conflicts = ComplianceCaseConflictDeclaration::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $conflictDecisions = ComplianceCaseConflictDecision::query()->whereIn('compliance_case_conflict_declaration_id', $conflicts->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $grants = ComplianceCaseAccessGrant::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $revocations = ComplianceCaseAccessGrantRevocation::query()->whereIn('compliance_case_access_grant_id', $grants->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $milestones = ComplianceCaseMilestone::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $milestoneEvents = ComplianceCaseMilestoneEvent::query()->whereIn('compliance_case_milestone_id', $milestones->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $milestoneDeliveries = ComplianceCaseMilestoneDelivery::query()->whereIn('compliance_case_milestone_id', $milestones->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $communications = ComplianceCaseCommunicationDecision::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $retention = ComplianceCaseRetentionClassification::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $dispositions = ComplianceCaseDispositionReview::query()->whereIn('compliance_case_retention_classification_id', $retention->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $reopens = ComplianceCaseReopenProposal::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $reopenReviews = ComplianceCaseReopenReview::query()->whereIn('compliance_case_reopen_proposal_id', $reopens->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $evidenceFiles = ComplianceCaseEvidenceFile::query()
            ->whereHas('submission', fn ($query) => $query->where('compliance_case_id', $case->id))
            ->orderBy('id')->lockForUpdate()->get();

        $files = [[
            'kind' => 'closure_package_pdf', 'size' => (int) $package->report_size, 'sha256' => $package->report_sha256,
        ]];
        foreach ($evidenceFiles as $file) {
            $files[] = [
                'kind' => 'evidence_file', 'id' => $file->id, 'size' => (int) $file->file_size_snapshot, 'sha256' => $file->sha256,
            ];
        }

        return [
            'schema_version' => 'archive/v1',
            'summary' => $summary,
            'closure_package' => $package->fingerprint,
            'closure_package_review' => $packageReview->fingerprint,
            'conflicts' => $conflicts->pluck('fingerprint')->all(),
            'conflict_decisions' => $conflictDecisions->pluck('fingerprint')->all(),
            'access_grants' => $grants->pluck('fingerprint')->all(),
            'access_grant_revocations' => $revocations->pluck('fingerprint')->all(),
            'milestones' => $milestones->pluck('fingerprint')->all(),
            'milestone_events' => $milestoneEvents->pluck('fingerprint')->all(),
            'milestone_deliveries' => $milestoneDeliveries->pluck('fingerprint')->all(),
            'communications' => $communications->pluck('fingerprint')->all(),
            'retention_classifications' => $retention->pluck('fingerprint')->all(),
            'disposition_reviews' => $dispositions->pluck('fingerprint')->all(),
            'reopen_proposals' => $reopens->pluck('fingerprint')->all(),
            'reopen_reviews' => $reopenReviews->pluck('fingerprint')->all(),
            'closure_source_fingerprints' => data_get($package->report_snapshot, 'source_fingerprints', []),
            'files' => $files,
        ];
    }

    /** @param array<string,mixed> $sources */
    private function buildArchive(ComplianceCase $case, ComplianceCaseClosureReport $package, array $sources): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'fynix-cc-archive-');
        if ($temporaryPath === false) {
            throw ValidationException::withMessages(['case' => 'The archive workspace could not be created.']);
        }

        try {
            $zip = new \ZipArchive;
            if ($zip->open($temporaryPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw ValidationException::withMessages(['case' => 'The archive could not be assembled.']);
            }
            $zip->addFromString('manifest.json', CanonicalJson::encode($sources));
            $zip->addFromString('files/closure-package.pdf', app(FileAccess::class)->verifiedContents(
                $package->report_disk, $package->report_path, $package->report_size, $package->report_sha256,
                10 * 1024 * 1024, 'The closure package could not be verified for archive.',
                'The closure package no longer matches its governed fingerprint.',
            ));
            $evidenceFiles = ComplianceCaseEvidenceFile::query()
                ->whereHas('submission', fn ($query) => $query->where('compliance_case_id', $case->id))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($evidenceFiles as $file) {
                $bytes = app(FileAccess::class)->verifiedContents(
                    $file->disk_snapshot, $file->file_path_snapshot, (int) $file->file_size_snapshot, $file->sha256,
                    10 * 1024 * 1024, 'A retained evidence file could not be verified for archive.',
                    'A retained evidence file no longer matches its governed fingerprint.',
                );
                $name = basename((string) $file->file_name_snapshot);
                $zip->addFromString('files/evidence/'.$file->id.'-'.$name, $bytes);
            }
            $zip->close();
            $bytes = file_get_contents($temporaryPath);
            if ($bytes === false || strlen($bytes) > 75 * 1024 * 1024) {
                throw ValidationException::withMessages(['case' => 'The retained archive exceeds the 75 MiB bound.']);
            }

            return $bytes;
        } finally {
            @unlink($temporaryPath);
        }
    }
}
