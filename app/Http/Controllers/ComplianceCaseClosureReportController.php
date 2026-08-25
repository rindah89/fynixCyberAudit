<?php

namespace App\Http\Controllers;

use App\Models\ComplianceCaseClosureReport;
use App\Services\ComplianceCaseClosureReportDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseClosureReportController extends Controller
{
    public function download(Request $request, ComplianceCaseClosureReport $report, ComplianceCaseClosureReportDownload $download): StreamedResponse
    {
        return $download->download($request->user(), $report);
    }
}
