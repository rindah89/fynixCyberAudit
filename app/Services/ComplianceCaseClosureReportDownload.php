<?php

namespace App\Services;

use App\Models\ComplianceCaseClosureReport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseClosureReportDownload
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function download(User $actor, ComplianceCaseClosureReport $report): StreamedResponse
    {
        $report->loadMissing('complianceCase');
        abort_unless($actor->can('view', $report->complianceCase), 403);
        $bytes = $this->verifiedBytes($report);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'Compliance-Case-Closure-'.$report->complianceCase->number.'-v'.$report->version.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function verifiedBytes(ComplianceCaseClosureReport $report): string
    {
        $storage = Storage::disk($report->report_disk);
        abort_unless($storage->exists($report->report_path), 404);
        $stream = $storage->readStream($report->report_path);
        abort_unless(is_resource($stream), 404);
        try {
            $bytes = '';
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                abort_if($chunk === false, 409, 'The retained compliance case closure report could not be verified.');
                $size += strlen($chunk);
                abort_if($size > self::MAX_BYTES, 409, 'The retained compliance case closure report exceeds the verification bound.');
                $bytes .= $chunk;
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        abort_unless($size === $report->report_size && hash_final($hash) === $report->report_sha256, 409,
            'The retained compliance case closure report no longer matches its governed fingerprint.');

        return $bytes;
    }
}
