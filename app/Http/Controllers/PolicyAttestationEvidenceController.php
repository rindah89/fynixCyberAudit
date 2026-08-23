<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\PolicyAttestationEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyAttestationEvidenceController extends Controller
{
    public function download(Request $request, PolicyAttestationEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamPolicyAttestationEvidence($request->user(), $evidence);
    }
}
