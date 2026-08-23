<?php

namespace App\Services;

use App\Access\FileAccess;
use App\Enums\ResponseStatus;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GovernedEvidenceSnapshotter
{
    /**
     * @param  list<int>  $attachmentIds
     * @param  list<array{disk: string, path: string}>  $retainedCopies
     * @return list<array{file_attachment_id: int, data_request_response_id_snapshot: int, response_status_snapshot: string, data_request_id_snapshot: int, audit_id_snapshot: int, disk_snapshot: string, file_name_snapshot: string, file_path_snapshot: string, file_size_snapshot: int, sha256: string}>
     */
    public function snapshot(
        array $attachmentIds,
        User $actor,
        string $namespace,
        string $snapshotBatch,
        array &$retainedCopies,
    ): array {
        $orderedIds = collect($attachmentIds)->map(fn ($id): int => (int) $id)->sort()->values();
        $attachments = FileAttachment::query()->whereKey($orderedIds)->lockForUpdate()->get()->keyBy('id');
        $responseIds = $attachments->pluck('data_request_response_id')->filter()->unique()->sort()->values();
        $responses = DataRequestResponse::query()->whereKey($responseIds)->lockForUpdate()->get()->keyBy('id');
        $requestIds = $responses->pluck('data_request_id')->unique()->sort()->values();
        $requests = DataRequest::query()->whereKey($requestIds)->lockForUpdate()->get()->keyBy('id');
        $auditIds = $requests->pluck('audit_id')->unique()->sort()->values();
        $audits = Audit::query()->whereKey($auditIds)->lockForUpdate()->get()->keyBy('id');
        DB::table('audit_user')->whereIn('audit_id', $auditIds)->orderBy('audit_id')->orderBy('user_id')->lockForUpdate()->get();
        $audits->load('members');
        $disk = setting('storage.driver', 'private');
        $storage = Storage::disk($disk);
        $fileAccess = app(FileAccess::class);
        $hasher = app(GovernedEvidenceHasher::class);
        $snapshots = [];
        $totalBytes = 0;

        foreach ($orderedIds as $index => $attachmentId) {
            /** @var FileAttachment|null $attachment */
            $attachment = $attachments->get($attachmentId);
            $response = $attachment ? $responses->get($attachment->data_request_response_id) : null;
            $request = $response ? $requests->get($response->data_request_id) : null;
            $audit = $request ? $audits->get($request->audit_id) : null;
            $errorKey = "evidence_attachment_ids.{$index}";
            if (! $attachment || ! $response || ! $request || ! $audit || $response->status !== ResponseStatus::ACCEPTED) {
                throw ValidationException::withMessages([$errorKey => 'Governed evidence must be an attachment on an accepted data-request response.']);
            }
            $request->setRelation('audit', $audit);
            $response->setRelation('dataRequest', $request);
            $attachment->setRelation('dataRequestResponse', $response);
            $attachment->setRelation('audit', $audit);
            if (! $fileAccess->canDownloadFileAttachment($actor, $attachment)) {
                throw ValidationException::withMessages([$errorKey => 'You must be authorized to access each governed evidence attachment.']);
            }

            $path = $fileAccess->normalizePath($attachment->file_path);
            $snapshotPath = "governed-evidence/{$namespace}/{$snapshotBatch}/{$attachment->id}";
            try {
                if (! $storage->exists($path)) {
                    throw new \RuntimeException('missing');
                }
                $declaredSize = $storage->size($path);
                if ($declaredSize > GovernedEvidenceHasher::MAX_FILE_BYTES || ($totalBytes + $declaredSize) > GovernedEvidenceHasher::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages([$errorKey => 'Governed evidence is limited to 10 MiB per file and 50 MiB in total.']);
                }
                $stream = $storage->readStream($path);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('unreadable');
                }
                try {
                    $hashResult = $hasher->snapshotStream($stream, $storage, $snapshotPath, $totalBytes, $errorKey);
                    $retainedCopies[] = ['disk' => $disk, 'path' => $snapshotPath];
                } finally {
                    fclose($stream);
                }
                $totalBytes += $hashResult['bytes'];
                $size = $hashResult['bytes'];
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw ValidationException::withMessages([$errorKey => 'The governed evidence content must exist and be readable on the configured private storage disk.']);
            }

            $snapshots[] = [
                'file_attachment_id' => $attachment->id,
                'data_request_response_id_snapshot' => $response->id,
                'response_status_snapshot' => $response->status->value,
                'data_request_id_snapshot' => $request->id,
                'audit_id_snapshot' => $request->audit_id,
                'disk_snapshot' => $disk,
                'file_name_snapshot' => $attachment->file_name ?? basename($path),
                'file_path_snapshot' => $snapshotPath,
                'file_size_snapshot' => $size,
                'sha256' => $hashResult['sha256'],
            ];
        }

        return $snapshots;
    }

    /** @param  list<array{disk: string, path: string}>  $retainedCopies */
    public function cleanup(array $retainedCopies): void
    {
        foreach (collect($retainedCopies)->unique(fn (array $copy): string => $copy['disk'].'|'.$copy['path']) as $copy) {
            try {
                Storage::disk($copy['disk'])->delete($copy['path']);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
        }
    }
}
