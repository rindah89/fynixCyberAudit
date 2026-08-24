<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\AuditFindingFollowUpEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditFindingFollowUpEvidenceController extends Controller
{
    public function download(Request $request, AuditFindingFollowUpEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamAuditFindingFollowUpEvidence($request->user(), $evidence);
    }
}
