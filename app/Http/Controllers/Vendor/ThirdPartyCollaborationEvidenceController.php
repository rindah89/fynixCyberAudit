<?php

namespace App\Http\Controllers\Vendor;

use App\Access\FileAccess;
use App\Http\Controllers\Controller;
use App\Models\ThirdPartyEngagementCollaborationEvidence;
use App\Models\VendorUser;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ThirdPartyCollaborationEvidenceController extends Controller
{
    public function download(ThirdPartyEngagementCollaborationEvidence $evidence, FileAccess $files): StreamedResponse
    {
        $actor = Auth::guard('vendor')->user();
        abort_unless($actor instanceof VendorUser, 403);

        return $files->streamVendorThirdPartyCollaborationEvidence($actor, $evidence);
    }
}
