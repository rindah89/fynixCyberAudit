<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\SurveyAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SurveyAttachmentController extends Controller
{
    /**
     * Download a survey attachment (authenticated users only).
     */
    public function download(Request $request, SurveyAttachment $attachment, FileAccess $fileAccess): StreamedResponse
    {
        $fileAccess->authorizeSurveyAttachment($request->user(), $attachment);

        return $fileAccess->stream(
            $attachment->getStorageDisk(),
            $attachment->file_path,
            $attachment->file_name
        );
    }
}
