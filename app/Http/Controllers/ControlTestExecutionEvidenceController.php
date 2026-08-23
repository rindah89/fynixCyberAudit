<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\ControlTestExecutionEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ControlTestExecutionEvidenceController extends Controller
{
    public function download(Request $request, ControlTestExecutionEvidence $evidence, FileAccess $fileAccess): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $fileAccess->streamControlTestExecutionEvidence($actor, $evidence);
    }
}
