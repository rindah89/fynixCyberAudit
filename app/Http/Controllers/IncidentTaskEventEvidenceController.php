<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\IncidentTaskEventEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentTaskEventEvidenceController extends Controller
{
    public function download(Request $request, IncidentTaskEventEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamIncidentTaskEventEvidence($request->user(), $evidence);
    }
}
