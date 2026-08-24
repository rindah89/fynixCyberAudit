<?php

namespace App\Services;

use App\Access\FileAccess;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GovernedVendorDocumentSnapshotter
{
    /** @param list<int> $documentIds @param list<array{disk:string,path:string}> $retainedCopies */
    public function snapshot(array $documentIds, VendorUser $actor, int $vendorId, string $batch, array &$retainedCopies): array
    {
        $ids = collect($documentIds)->map(fn ($id): int => (int) $id)->sort()->values();
        $documents = VendorDocument::query()->whereKey($ids)->whereNull('deleted_at')->lockForUpdate()->get()->keyBy('id');
        $disk = setting('storage.driver', 'private');
        $storage = Storage::disk($disk);
        $total = 0;
        $snapshots = [];
        foreach ($ids as $index => $id) {
            $document = $documents->get($id);
            $error = "vendor_document_ids.{$index}";
            if (! $document || (int) $document->vendor_id !== $vendorId || ! app(FileAccess::class)->canDownloadVendorDocument($actor, $document)) {
                throw ValidationException::withMessages([$error => 'Each evidence document must be an accessible document for this engagement vendor.']);
            }
            $source = app(FileAccess::class)->normalizePath($document->file_path);
            $destination = "governed-evidence/third-party-collaboration/{$batch}/{$document->id}";
            try {
                if (! $storage->exists($source)) {
                    throw new \RuntimeException('missing');
                }
                $stream = $storage->readStream($source);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('unreadable');
                }
                try {
                    $result = app(GovernedEvidenceHasher::class)->snapshotStream($stream, $storage, $destination, $total, $error);
                    $retainedCopies[] = ['disk' => $disk, 'path' => $destination];
                } finally {
                    fclose($stream);
                }
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw ValidationException::withMessages([$error => 'The selected provider document content must exist on private storage.']);
            }
            $total += $result['bytes'];
            $snapshots[] = ['vendor_document_id' => $document->id, 'vendor_id_snapshot' => $vendorId, 'linked_by_vendor_user_id' => $actor->id,
                'document_status_snapshot' => $document->status->value, 'disk_snapshot' => $disk, 'file_name_snapshot' => $document->file_name ?: basename($source),
                'file_path_snapshot' => $destination, 'file_size_snapshot' => $result['bytes'], 'sha256' => $result['sha256']];
        }

        return $snapshots;
    }

    public function cleanup(array $copies): void
    {
        foreach (collect($copies)->unique(fn (array $copy): string => $copy['disk'].'|'.$copy['path']) as $copy) {
            try {
                Storage::disk($copy['disk'])->delete($copy['path']);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
