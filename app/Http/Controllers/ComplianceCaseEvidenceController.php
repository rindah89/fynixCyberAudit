<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\ComplianceCaseEvidenceFile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseEvidenceController extends Controller
{
    public function download(Request $request, ComplianceCaseEvidenceFile $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamComplianceCaseEvidence($request->user(), $evidence);
    }
}
