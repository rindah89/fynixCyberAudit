<?php

namespace App\Http\Controllers\Vendor;

use App\Access\FileAccess;
use App\Http\Controllers\Controller;
use App\Models\VendorDocument;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorDocumentController extends Controller
{
    public function download(VendorDocument $vendorDocument, FileAccess $fileAccess): StreamedResponse
    {
        $vendorUser = Auth::guard('vendor')->user();

        if (! $fileAccess->canDownloadVendorDocument($vendorUser, $vendorDocument)) {
            abort(403, 'You do not have access to this document.');
        }

        return $fileAccess->stream(
            config('filesystems.default'),
            $vendorDocument->file_path,
            $vendorDocument->file_name
        );
    }
}
