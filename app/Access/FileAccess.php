<?php

namespace App\Access;

use App\Models\AiJob;
use App\Models\FileAttachment;
use App\Models\IncidentEvidence;
use App\Models\SurveyAttachment;
use App\Models\TrustCenterDocument;
use App\Models\User;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
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

    public function canDownloadFileAttachment(Authenticatable $actor, FileAttachment $attachment): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        if ((int) $attachment->uploaded_by === (int) $actor->id) {
            return true;
        }

        $audit = $attachment->audit ?? $attachment->dataRequest?->audit ?? $attachment->dataRequestResponse?->dataRequest?->audit;

        if ($audit) {
            if ((int) $audit->manager_id === (int) $actor->id) {
                return true;
            }

            if ($audit->members()->whereKey($actor->id)->exists()) {
                return true;
            }
        }

        $dataRequest = $attachment->dataRequest ?? $attachment->dataRequestResponse?->dataRequest;

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
