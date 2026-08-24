<?php

namespace App\Services;

use App\Enums\AuditCloseoutDecision;
use App\Models\AuditCloseoutReview;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditCloseoutReport
{
    private const MAX_REPORT_BYTES = 10 * 1024 * 1024;

    public function download(AuditCloseoutReview $review): StreamedResponse
    {
        abort_unless($review->decision === AuditCloseoutDecision::Approved
            && $review->report_disk
            && $review->report_path
            && $review->report_size !== null
            && $review->report_sha256, 404);

        $storage = Storage::disk($review->report_disk);
        abort_unless($storage->exists($review->report_path), 404);
        $stream = $storage->readStream($review->report_path);
        abort_unless(is_resource($stream), 404);

        try {
            $bytes = '';
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                abort_if($chunk === false, 409, 'The retained final report could not be verified.');
                $size += strlen($chunk);
                abort_if($size > self::MAX_REPORT_BYTES, 409, 'The retained final report exceeds the verification bound.');
                $bytes .= $chunk;
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        abort_unless($size === (int) $review->report_size && hash_final($hash) === $review->report_sha256, 409, 'The retained final report no longer matches its governed fingerprint.');

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            'Final-Audit-Report-'.$review->submission->audit_id.'.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }
}
