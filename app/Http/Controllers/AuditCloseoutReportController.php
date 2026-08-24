<?php

namespace App\Http\Controllers;

use App\Models\AuditCloseoutReview;
use App\Services\AuditCloseoutReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditCloseoutReportController extends Controller
{
    public function download(AuditCloseoutReview $review, AuditCloseoutReport $reports): StreamedResponse
    {
        $review->loadMissing('submission.audit');
        $this->authorize('view', $review->submission->audit);

        return $reports->download($review);
    }
}
