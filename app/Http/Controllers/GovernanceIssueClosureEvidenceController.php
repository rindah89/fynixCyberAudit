<?php

namespace App\Http\Controllers;

use App\Access\FileAccess;
use App\Models\GovernanceIssueClosureEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GovernanceIssueClosureEvidenceController extends Controller
{
    public function download(Request $request, GovernanceIssueClosureEvidence $evidence, FileAccess $fileAccess): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $fileAccess->streamGovernanceClosureEvidence($actor, $evidence);
    }
}
