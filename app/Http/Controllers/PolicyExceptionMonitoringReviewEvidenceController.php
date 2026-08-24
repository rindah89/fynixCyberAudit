<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\PolicyExceptionMonitoringReviewEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyExceptionMonitoringReviewEvidenceController extends Controller
{
    public function download(
        Request $request,
        PolicyExceptionMonitoringReviewEvidence $evidence,
        FileAccess $fileAccess,
    ): StreamedResponse {
        /** @var User $actor */
        $actor = $request->user();

        return $fileAccess->streamPolicyExceptionMonitoringReviewEvidence($actor, $evidence);
    }
}
