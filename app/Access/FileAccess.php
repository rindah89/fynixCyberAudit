<?php

namespace App\Access;

use App\Models\AiJob;
use App\Models\AiMonitoringReviewEvidence;
use App\Models\ControlTestExecutionEvidence;
use App\Models\FileAttachment;
use App\Models\GovernanceIssueClosureEvidence;
use App\Models\GovernanceIssueLifecycle;
use App\Models\IncidentEvidence;
use App\Models\PolicyAttestationEvidence;
use App\Models\RecoveryExerciseEvidence;
use App\Models\RiskGovernanceReviewEvidence;
use App\Models\SurveyAttachment;
use App\Models\TrustCenterDocument;
use App\Models\User;
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
        $this->authorizePath($actor, $path);

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
                ->orWhereHas('riskGovernanceReviewEvidence'))
            ->exists()) {
            throw ValidationException::withMessages([
                'file_path' => 'Files referenced by governed evidence cannot be removed through product interfaces.',
            ]);
        }

        Storage::disk($disk)->delete($path);
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
