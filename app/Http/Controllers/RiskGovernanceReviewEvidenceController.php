<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\RiskGovernanceReviewEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RiskGovernanceReviewEvidenceController extends Controller
{
    public function download(Request $request, RiskGovernanceReviewEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamRiskGovernanceReviewEvidence($request->user(), $evidence);
    }
}
