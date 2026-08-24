<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\ThirdPartyEngagementCollaborationEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ThirdPartyCollaborationEvidenceController extends Controller
{
    public function download(Request $request, ThirdPartyEngagementCollaborationEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamThirdPartyCollaborationEvidence($request->user(), $evidence);
    }
}
