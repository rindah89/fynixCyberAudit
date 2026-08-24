<?php

namespace App\Http\Controllers;

use App\Models\IncidentFinalReport;
use App\Services\IncidentFinalReportDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentFinalReportController extends Controller
{
    public function download(Request $request, IncidentFinalReport $report, IncidentFinalReportDownload $download): StreamedResponse
    {
        return $download->download($request->user(), $report);
    }
}
