<?php

namespace App\Access;

use App\Models\AiJob;
use App\Models\AiMonitoringReviewEvidence;
use App\Models\AuditFindingFollowUpEvidence;
use App\Models\AuditProcedureExecutionEvidence;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseEvidenceFile;
use App\Models\ControlTestExecutionEvidence;
use App\Models\FileAttachment;
use App\Models\GovernanceIssueClosureEvidence;
use App\Models\GovernanceIssueLifecycle;
use App\Models\IncidentEvidence;
use App\Models\IncidentPhaseTransitionEvidence;
use App\Models\IncidentTaskEventEvidence;
use App\Models\PolicyAttestationEvidence;
use App\Models\PolicyExceptionMonitoringReviewEvidence;
use App\Models\RecoveryExerciseEvidence;
use App\Models\RiskGovernanceReviewEvidence;
use App\Models\SurveyAttachment;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationEvidence;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\TrustCenterDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorRiskReviewEvidence;
use App\Models\VendorUser;
use App\Support\Enterprise;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileAccess
{
    public function authorizePath(Authenticatable $actor, string $path): void
    {
        $path = $this->normalizePath($path);

        if ($attachment = FileAttachment::query()->where('file_path', $path)->first()) {
            if ($this->canDownloadFileAttachment($actor, $attachment)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($surveyAttachment = SurveyAttachment::query()->where('file_path', $path)->first()) {
            if ($this->canDownloadSurveyAttachment($actor, $surveyAttachment)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($vendorDocument = VendorDocument::query()->where('file_path', $path)->first()) {
            if ($this->canDownloadVendorDocument($actor, $vendorDocument)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($trustDocument = TrustCenterDocument::query()->where('file_path', $path)->first()) {
            if ($this->canDownloadTrustDocument($actor, $trustDocument)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($evidence = IncidentEvidence::query()->where('path', $path)->first()) {
            if ($actor instanceof User && $actor->can('Manage Incident Evidence')) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($job = AiJob::query()->where('result_path', $path)->first()) {
            if ($actor instanceof User && ((int) $job->created_by === (int) $actor->id || $actor->can('Manage Surveyor'))) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($report = ComplianceCaseClosureReport::query()->where('report_path', $path)->first()) {
            if ($actor instanceof User && $this->canStreamComplianceCaseClosureReport($actor, $report)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        if ($evidence = ComplianceCaseEvidenceFile::query()->where('file_path_snapshot', $path)->first()) {
            if ($actor instanceof User && $this->canStreamComplianceCaseEvidence($actor, $evidence)) {
                return;
            }

            abort(403, 'You do not have access to this file.');
        }

        abort(403, 'You do not have access to this file.');
    }

    public function authorizeSurveyAttachment(Authenticatable $actor, SurveyAttachment $attachment): void
    {
        if (! $this->canDownloadSurveyAttachment($actor, $attachment)) {
            abort(403, 'You do not have access to this file.');
        }
    }

    public function stream(string $disk, string $path, ?string $downloadName = null): StreamedResponse
    {
        $path = $this->normalizePath($path);
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            abort(404, 'File not found');
        }

        return $storage->download($path, $downloadName);
    }

    public function streamAuthorized(Authenticatable $actor, string $disk, string $path, ?string $downloadName = null): StreamedResponse
    {
        $path = $this->normalizePath($path);
        $this->authorizePath($actor, $path);

        if ($actor instanceof User && ($report = ComplianceCaseClosureReport::query()->where('report_path', $path)->first())) {
            return $this->streamComplianceCaseClosureReport($actor, $report);
        }
        if ($actor instanceof User && ($evidence = ComplianceCaseEvidenceFile::query()->where('file_path_snapshot', $path)->first())) {
            return $this->streamComplianceCaseEvidence($actor, $evidence);
        }

        return $this->stream($disk, $path, $downloadName);
    }

    public function streamGovernanceClosureEvidence(User $actor, GovernanceIssueClosureEvidence $evidence): StreamedResponse
    {
        $canViewLifecycle = GovernanceIssueLifecycle::query()->visibleTo($actor)
            ->whereKey($evidence->governance_issue_lifecycle_id)->exists();
        $attachment = $evidence->attachment()->with([
            'audit.members',
            'dataRequestResponse.dataRequest.audit.members',
        ])->first();
        if (! $canViewLifecycle || ! $attachment || ! $this->canDownloadFileAttachment($actor, $attachment)) {
            abort(403, 'You do not have access to this governed closure evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamComplianceCaseEvidence(User $actor, ComplianceCaseEvidenceFile $evidence): StreamedResponse
    {
        abort_unless($this->canStreamComplianceCaseEvidence($actor, $evidence), 403, 'You do not have access to this governed compliance-case evidence.');

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function putPrivate(string $disk, string $path, string $contents): bool
    {
        return Storage::disk($disk)->put($this->normalizePath($path), $contents, ['visibility' => 'private']);
    }

    public function deletePrivate(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($this->normalizePath($path));
    }

    public function verifiedContents(
        string $disk,
        string $path,
        int $expectedSize,
        string $expectedSha256,
        int $maxBytes,
        string $unreadableMessage,
        string $mismatchMessage,
    ): string {
        $path = $this->normalizePath($path);
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $stream = $storage->readStream($path);
        abort_unless(is_resource($stream), 404);
        try {
            $bytes = '';
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                abort_if($chunk === false, 409, $unreadableMessage);
                $size += strlen($chunk);
                abort_if($size > $maxBytes, 409, $unreadableMessage);
                $bytes .= $chunk;
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        abort_unless($size === $expectedSize && hash_final($hash) === $expectedSha256, 409, $mismatchMessage);

        return $bytes;
    }

    public function verifiedComplianceCaseClosureReport(ComplianceCaseClosureReport $report): string
    {
        return $this->verifiedContents(
            $report->report_disk,
            $report->report_path,
            $report->report_size,
            $report->report_sha256,
            10 * 1024 * 1024,
            'The retained compliance case closure report could not be verified.',
            'The retained compliance case closure report no longer matches its governed fingerprint.',
        );
    }

    public function streamComplianceCaseClosureReport(User $actor, ComplianceCaseClosureReport $report): StreamedResponse
    {
        abort_unless($this->canStreamComplianceCaseClosureReport($actor, $report), 403);
        $bytes = $this->verifiedComplianceCaseClosureReport($report);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'Compliance-Case-Closure-'.$report->complianceCase->number.'-v'.$report->version.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function streamControlTestExecutionEvidence(User $actor, ControlTestExecutionEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['execution.definition', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        if (! $evidence->execution || ! $actor->can('view', $evidence->execution->definition)
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed control-test evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamAuditProcedureExecutionEvidence(User $actor, AuditProcedureExecutionEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['execution.procedure.audit', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $audit = $evidence->execution?->procedure?->audit;
        if (! $audit || ! $actor->can('view', $audit)
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed audit-procedure evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function streamAiMonitoringReviewEvidence(User $actor, AiMonitoringReviewEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['review.useCase.aiSystem', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $system = $evidence->review?->useCase?->aiSystem;
        if (! $system || ! $actor->can('view', $system)
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed AI monitoring evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamPolicyExceptionMonitoringReviewEvidence(User $actor, PolicyExceptionMonitoringReviewEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing([
            'review.exception.policy',
            'attachment.audit.members',
            'attachment.dataRequestResponse.dataRequest.audit.members',
        ]);
        $policy = $evidence->review?->exception?->policy;
        $canViewWorkspace = $policy && ((int) $policy->owner_id === (int) $actor->id
            || $actor->can('Read Policies') || $actor->can('Update Policies'));
        if (! $canViewWorkspace
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed policy-exception monitoring evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamVendorRiskReviewEvidence(User $actor, VendorRiskReviewEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['review.vendor', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $vendor = $evidence->review?->vendor;
        $canViewWorkspace = $vendor && ($actor->can('Manage Third Party Risk') || (int) $vendor->vendor_manager_id === (int) $actor->id);
        if (! $canViewWorkspace
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed third-party review evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamRecoveryExerciseEvidence(User $actor, RecoveryExerciseEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['exercise.recoveryPlan.businessService', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $service = $evidence->exercise?->recoveryPlan?->businessService;
        $canViewWorkspace = Enterprise::enabled('resilience') && $service
            && ($actor->can('Manage Resilience') || (int) $service->owner_id === (int) $actor->id);
        if (! $canViewWorkspace
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed recovery-exercise evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamPolicyAttestationEvidence(User $actor, PolicyAttestationEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['attestation.obligation.policy', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $obligation = $evidence->attestation?->obligation;
        if (! $obligation || ! $actor->can('view', $obligation)
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed policy-attestation evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamRiskGovernanceReviewEvidence(User $actor, RiskGovernanceReviewEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['review.risk.governanceProfile', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $risk = $evidence->review?->risk;
        $canViewWorkspace = $risk && ($actor->can('Manage Risk Portfolio') || $actor->can('Read Risks')
            || (int) $risk->governanceProfile?->owner_id === (int) $actor->id);
        if (! $canViewWorkspace
            || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed risk-review evidence.');
        }

        return $this->stream(
            $evidence->disk_snapshot,
            $evidence->file_path_snapshot,
            $evidence->file_name_snapshot,
        );
    }

    public function streamAuditFindingFollowUpEvidence(User $actor, AuditFindingFollowUpEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['followUp.remediation.finding.audit', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $followUp = $evidence->followUp;
        $finding = $followUp?->remediation?->finding;
        $canViewFinding = $finding && ((int) $finding->accountable_owner_id === (int) $actor->id
            || $actor->can('view', $finding->audit));
        if (! $canViewFinding || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed audit-finding follow-up evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function streamIncidentTaskEventEvidence(User $actor, IncidentTaskEventEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['event.task.incident', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $task = $evidence->event?->task;
        $incident = $task?->incident;
        $canViewTask = $incident && ($actor->can('view', $incident) || $actor->can('Manage Incident Tasks') || $task->assignee_id === $actor->id);
        if (! $canViewTask || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed incident task evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function streamIncidentPhaseTransitionEvidence(User $actor, IncidentPhaseTransitionEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing(['transition.incident', 'attachment.audit.members', 'attachment.dataRequestResponse.dataRequest.audit.members']);
        $incident = $evidence->transition?->incident;
        if (! $incident || ! $actor->can('view', $incident) || ! $evidence->attachment || ! $this->canDownloadFileAttachment($actor, $evidence->attachment)) {
            abort(403, 'You do not have access to this governed incident phase evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function streamThirdPartyCollaborationEvidence(User $actor, ThirdPartyEngagementCollaborationEvidence $evidence): StreamedResponse
    {
        $event = ThirdPartyEngagementCollaborationEvent::query()->find($evidence->third_party_engagement_collaboration_event_id);
        $collaboration = $event ? ThirdPartyEngagementCollaborationRequest::query()->find($event->third_party_engagement_collaboration_request_id) : null;
        $engagement = $collaboration ? ThirdPartyEngagement::query()->find($collaboration->third_party_engagement_id) : null;
        $vendor = $engagement ? Vendor::withTrashed()->find($engagement->vendor_id) : null;
        $document = VendorDocument::withTrashed()->find($evidence->vendor_document_id);
        $workspace = $engagement && $vendor && ($actor->can('Manage Third Party Risk') || $actor->can('Read Vendors') || (int) $vendor->vendor_manager_id === (int) $actor->id);
        if (! $workspace || ! $document || $document->trashed() || ! $this->canDownloadVendorDocument($actor, $document)) {
            abort(403, 'You do not have access to this governed collaboration evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function streamVendorThirdPartyCollaborationEvidence(VendorUser $actor, ThirdPartyEngagementCollaborationEvidence $evidence): StreamedResponse
    {
        $event = ThirdPartyEngagementCollaborationEvent::query()->find($evidence->third_party_engagement_collaboration_event_id);
        $collaboration = $event ? ThirdPartyEngagementCollaborationRequest::query()->find($event->third_party_engagement_collaboration_request_id) : null;
        $engagement = $collaboration ? ThirdPartyEngagement::query()->find($collaboration->third_party_engagement_id) : null;
        $document = VendorDocument::withTrashed()->find($evidence->vendor_document_id);
        if (! $collaboration || ! $engagement || (int) $collaboration->current_recipient_vendor_user_id !== (int) $actor->id
            || (int) $engagement->vendor_id !== (int) $actor->vendor_id || ! $document || $document->trashed() || ! $this->canDownloadVendorDocument($actor, $document)) {
            abort(403, 'You do not have access to this governed collaboration evidence.');
        }

        return $this->stream($evidence->disk_snapshot, $evidence->file_path_snapshot, $evidence->file_name_snapshot);
    }

    public function deleteUnreferencedFileAttachmentPath(string $disk, string $path): void
    {
        $path = $this->normalizePath($path);
        if (FileAttachment::query()->where('file_path', $path)
            ->where(fn ($query) => $query->whereHas('closureEvidence')
                ->orWhereHas('controlTestEvidence')
                ->orWhereHas('aiMonitoringEvidence')
                ->orWhereHas('vendorRiskReviewEvidence')
                ->orWhereHas('recoveryExerciseEvidence')
                ->orWhereHas('policyAttestationEvidence')
                ->orWhereHas('riskGovernanceReviewEvidence')
                ->orWhereHas('auditFindingFollowUpEvidence')
                ->orWhereHas('auditProcedureExecutionEvidence')
                ->orWhereHas('incidentPhaseTransitionEvidence')
                ->orWhereHas('incidentTaskEventEvidence')
                ->orWhereHas('complianceCaseEvidence'))
            ->exists()
            || ComplianceCaseEvidenceFile::query()->where('file_path_snapshot', $path)->exists()
            || ComplianceCaseClosureReport::query()->where('report_path', $path)->exists()) {
            throw ValidationException::withMessages([
                'file_path' => 'Files referenced by governed evidence cannot be removed through product interfaces.',
            ]);
        }

        Storage::disk($disk)->delete($path);
    }

    private function canStreamComplianceCaseClosureReport(User $actor, ComplianceCaseClosureReport $report): bool
    {
        $report->loadMissing('complianceCase');

        return Enterprise::enabled('compliance_cases') && $report->complianceCase !== null && $actor->can('view', $report);
    }

    private function canStreamComplianceCaseEvidence(User $actor, ComplianceCaseEvidenceFile $evidence): bool
    {
        $evidence->loadMissing('submission.complianceCase');
        $case = $evidence->submission?->complianceCase;

        return Enterprise::enabled('compliance_cases') && $case !== null && $actor->can('view', $case);
    }

    public function canDownloadFileAttachment(Authenticatable $actor, FileAttachment $attachment): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        if ((int) $attachment->uploaded_by === (int) $actor->id) {
            return true;
        }

        $audit = $attachment->audit ?? $attachment->dataRequestResponse?->dataRequest?->audit;

        if ($audit) {
            if ((int) $audit->manager_id === (int) $actor->id) {
                return true;
            }

            $isMember = $audit->relationLoaded('members')
                ? $audit->members->contains(fn (User $member): bool => $member->id === $actor->id)
                : $audit->members()->whereKey($actor->id)->exists();
            if ($isMember) {
                return true;
            }
        }

        $dataRequest = $attachment->dataRequestResponse?->dataRequest;

        if ($dataRequest && in_array($actor->id, [(int) $dataRequest->created_by_id, (int) $dataRequest->assigned_to_id], true)) {
            return true;
        }

        $response = $attachment->dataRequestResponse;

        return $response !== null && (int) $response->requestee_id === (int) $actor->id;
    }

    public function canDownloadSurveyAttachment(Authenticatable $actor, SurveyAttachment $attachment): bool
    {
        $survey = $attachment->answer?->survey;

        if ($actor instanceof VendorUser) {
            return $survey !== null && app(VendorAccess::class)->mayOpenSurvey($actor, $survey);
        }

        if (! $actor instanceof User) {
            return false;
        }

        if ((int) $attachment->uploaded_by === (int) $actor->id) {
            return true;
        }

        return $actor->can('Read Surveys');
    }

    public function canDownloadVendorDocument(Authenticatable $actor, VendorDocument $document): bool
    {
        if ($actor instanceof VendorUser) {
            return (int) $document->vendor_id === (int) $actor->vendor_id;
        }

        return $actor instanceof User && $actor->can('Read Vendors');
    }

    public function canDownloadTrustDocument(Authenticatable $actor, TrustCenterDocument $document): bool
    {
        if ($document->is_active && method_exists($document, 'isPublic') && $document->isPublic()) {
            return true;
        }

        return $actor instanceof User && $actor->can('Manage Trust Center');
    }

    public function normalizePath(string $path): string
    {
        $path = urldecode($path);

        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(403, 'Invalid file path');
        }

        return $path;
    }
}
