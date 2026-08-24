<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\IncidentPhaseTransitionEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentPhaseTransitionEvidenceController extends Controller
{
    public function download(Request $request, IncidentPhaseTransitionEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamIncidentPhaseTransitionEvidence($request->user(), $evidence);
    }
}
