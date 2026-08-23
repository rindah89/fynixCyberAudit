<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\VendorRiskReviewEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorRiskReviewEvidenceController extends Controller
{
    public function download(Request $request, VendorRiskReviewEvidence $evidence, FileAccess $files): StreamedResponse
    {
        return $files->streamVendorRiskReviewEvidence($request->user(), $evidence);
    }
}
