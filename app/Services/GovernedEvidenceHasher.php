<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Validation\ValidationException;

class GovernedEvidenceHasher
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    private const CHUNK_BYTES = 8192;

    /**
     * @param  resource  $stream
     * @return array{sha256: string, bytes: int}
     */
    public function snapshotStream(
        $stream,
        FilesystemAdapter $storage,
        string $destinationPath,
        int $currentTotalBytes,
        string $errorKey,
    ): array {
        $hash = hash_init('sha256');
        $bytesRead = 0;
        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($snapshot)) {
            throw ValidationException::withMessages([$errorKey => 'The closure evidence snapshot could not be prepared.']);
        }

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw ValidationException::withMessages([$errorKey => 'The closure evidence content could not be read.']);
                }
                $chunkBytes = strlen($chunk);
                if ($chunkBytes === 0) {
                    break;
                }
                $bytesRead += $chunkBytes;
                if ($bytesRead > self::MAX_FILE_BYTES || ($currentTotalBytes + $bytesRead) > self::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages([$errorKey => 'Closure evidence is limited to 10 MiB per file and 50 MiB in total.']);
                }
                hash_update($hash, $chunk);
                if (fwrite($snapshot, $chunk) !== $chunkBytes) {
                    throw ValidationException::withMessages([$errorKey => 'The closure evidence snapshot could not be written.']);
                }
            }

            rewind($snapshot);
            if (! $storage->writeStream($destinationPath, $snapshot)) {
                throw ValidationException::withMessages([$errorKey => 'The closure evidence snapshot could not be retained.']);
            }
        } catch (\Throwable $exception) {
            $storage->delete($destinationPath);

            throw $exception;
        } finally {
            fclose($snapshot);
        }

        return ['sha256' => hash_final($hash), 'bytes' => $bytesRead];
    }
}
