<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\AiMonitoringReviewEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiMonitoringReviewEvidenceController extends Controller
{
    public function download(Request $request, AiMonitoringReviewEvidence $evidence, FileAccess $fileAccess): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $fileAccess->streamAiMonitoringReviewEvidence($actor, $evidence);
    }
}
