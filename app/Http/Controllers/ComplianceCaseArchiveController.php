<?php

namespace App\Http\Controllers;

use App\ComplianceCases\ComplianceCaseArchiveManager;
use App\Models\ComplianceCaseArchiveManifest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseArchiveController extends Controller
{
    public function download(Request $request, ComplianceCaseArchiveManifest $archive, ComplianceCaseArchiveManager $manager): StreamedResponse
    {
        $archive->load(['complianceCase', 'review']);

        return $manager->download($request->user(), $archive);
    }
}
