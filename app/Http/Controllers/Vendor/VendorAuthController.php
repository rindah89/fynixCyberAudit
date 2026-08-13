<?php

namespace App\Http\Controllers\Vendor;

use App\Access\VendorAccess;
use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\VendorUser;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorAuthController extends Controller
{
    public function magicLogin(Request $request, VendorUser $vendorUser)
    {
        if (! $request->hasValidSignature()) {
            abort(401, 'This link has expired or is invalid.');
        }

        if ($vendorUser->trashed()) {
            abort(403, 'This account has been deactivated.');
        }

        Auth::guard('vendor')->login($vendorUser);

        $vendorUser->update(['last_login_at' => now()]);

        session()->regenerate();

        if (! $vendorUser->hasPassword()) {
            return redirect()->route('filament.vendor.auth.set-password');
        }

        return redirect()->intended(Filament::getPanel('vendor')->getUrl());
    }

    /**
     * Magic link to access a specific survey.
     * Redirects to the survey access page where users can login, register, or set password.
     */
    public function surveyMagicLink(Request $request, Survey $survey, VendorAccess $vendorAccess)
    {
        if (! $request->hasValidSignature()) {
            abort(401, 'This link has expired or is invalid.');
        }

        if (! $survey->vendor_id) {
            abort(404, 'Survey not found.');
        }

        $email = $survey->respondent_email;

        if (Auth::guard('vendor')->check()) {
            $vendorUser = Auth::guard('vendor')->user();

            if ($vendorAccess->mayOpenSurvey($vendorUser, $survey)) {
                return redirect()->route('filament.vendor.resources.surveys.respond', ['record' => $survey->id]);
            }

            Auth::guard('vendor')->logout();
        }

        $vendorAccess->grantSurveyClaim($survey, $email);

        return redirect()->to($vendorAccess->surveyAccessUrl($survey, $email));
    }
}
