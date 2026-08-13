<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaProxyController extends Controller
{
    /**
     * Serve private media files through Laravel proxy
     * This allows browser-based editors to display private S3 images
     */
    public function show(Request $request, string $path, FileAccess $fileAccess): StreamedResponse
    {
        $filePath = $fileAccess->normalizePath($path);
        $fileAccess->authorizePath($request->user(), $filePath);

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($filePath)) {
            abort(404, 'File not found');
        }

        try {
            $mimeType = $disk->mimeType($filePath);
            $size = $disk->size($filePath);
        } catch (Exception $e) {
            abort(500, 'Unable to retrieve file metadata');
        }

        return new StreamedResponse(function () use ($disk, $filePath) {
            $stream = $disk->readStream($filePath);
            if ($stream === false) {
                abort(500, 'Unable to read file');
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline',
        ]);
    }
}
