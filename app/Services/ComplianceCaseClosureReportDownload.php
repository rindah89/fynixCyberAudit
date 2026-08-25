<?php

namespace App\Services;

use App\Access\FileAccess;
use App\Models\ComplianceCaseClosureReport;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceCaseClosureReportDownload
{
    public function download(User $actor, ComplianceCaseClosureReport $report): StreamedResponse
    {
        return app(FileAccess::class)->streamComplianceCaseClosureReport($actor, $report);
    }

    public function verifiedBytes(ComplianceCaseClosureReport $report): string
    {
        return app(FileAccess::class)->verifiedComplianceCaseClosureReport($report);
    }
}
