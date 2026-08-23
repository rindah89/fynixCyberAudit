<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\RecoveryExerciseEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecoveryExerciseEvidenceController extends Controller
{
    public function download(Request $request, RecoveryExerciseEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamRecoveryExerciseEvidence($request->user(), $evidence);
    }
}
