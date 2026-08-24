<?php

namespace App\Services;

use App\Access\FileAccess;
use App\Models\FileAttachment;
use App\Models\IncidentFinalReport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentFinalReportDownload
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function download(User $actor, IncidentFinalReport $report): StreamedResponse
    {
        $report->loadMissing('incident');
        abort_unless($actor->can('view', $report->incident) && $actor->can('Manage Incident Evidence'), 403);
        $attachments = FileAttachment::query()->whereKey($report->evidence_attachment_ids ?? [])->with([
            'audit.members', 'dataRequestResponse.dataRequest.audit.members',
        ])->get()->keyBy('id');
        foreach ($report->evidence_attachment_ids ?? [] as $id) {
            $attachment = $attachments->get($id);
            abort_unless($attachment && app(FileAccess::class)->canDownloadFileAttachment($actor, $attachment), 403,
                'You must retain access to every evidence file represented in this report.');
        }
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
                abort_if($chunk === false, 409, 'The retained incident report could not be verified.');
                $size += strlen($chunk);
                abort_if($size > self::MAX_BYTES, 409, 'The retained incident report exceeds the verification bound.');
                $bytes .= $chunk;
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        abort_unless($size === $report->report_size && hash_final($hash) === $report->report_sha256, 409,
            'The retained incident report no longer matches its governed fingerprint.');

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        },
            'Final-Incident-Report-'.$report->incident->number.'-v'.$report->version.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
