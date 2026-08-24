<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\AuditProcedureExecutionEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditProcedureExecutionEvidenceController extends Controller
{
    public function download(Request $request, AuditProcedureExecutionEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamAuditProcedureExecutionEvidence($request->user(), $evidence);
    }
}
