<?php

use App\Access\FileAccess;
use App\Filament\Vendor\Pages\Auth\SurveyAccess;
use App\Http\Controllers\AiMonitoringReviewEvidenceController;
use App\Http\Controllers\AuditCloseoutReportController;
use App\Http\Controllers\AuditFindingFollowUpEvidenceController;
use App\Http\Controllers\AuditProcedureExecutionEvidenceController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\ControlTestExecutionEvidenceController;
use App\Http\Controllers\GovernanceIssueClosureEvidenceController;
use App\Http\Controllers\IncidentTaskEventEvidenceController;
use App\Http\Controllers\MediaProxyController;
use App\Http\Controllers\PolicyAttestationEvidenceController;
use App\Http\Controllers\PolicyExceptionMonitoringReviewEvidenceController;
use App\Http\Controllers\RecoveryExerciseEvidenceController;
use App\Http\Controllers\RiskGovernanceReviewEvidenceController;
use App\Http\Controllers\SurveyAttachmentController;
use App\Http\Controllers\TrustCenterController;
use App\Http\Controllers\Vendor\VendorAuthController;
use App\Http\Controllers\Vendor\VendorDocumentController;
use App\Http\Controllers\VendorRiskReviewEvidenceController;
use App\Livewire\PasswordResetPage;
use App\Models\Survey;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;

// Health check endpoint for load balancers and Docker health checks
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});

Route::get('/', function () {
    return redirect()->route('filament.app.auth.login');
});

// override default login route to point to Filament login
Route::get('login', function () {
    return redirect()->route('filament.app.auth.login');
})->name('login');

Route::middleware(['auth'])->group(function () {

    Route::get('/app/reset-password', PasswordResetPage::class)->name('password-reset-page');

    Route::get('/app/priv-storage/{filepath}', function ($filepath) {
        $fileAccess = app(FileAccess::class);

        return $fileAccess->streamAuthorized(request()->user(), 'private', $filepath);
    })->where('filepath', '.*')->name('priv-storage');

    Route::get('/app/governance-closure-evidence/{evidence}/download', [GovernanceIssueClosureEvidenceController::class, 'download'])
        ->name('governance-closure-evidence.download');
    Route::get('/app/control-test-execution-evidence/{evidence}/download', [ControlTestExecutionEvidenceController::class, 'download'])
        ->name('control-test-execution-evidence.download');
    Route::get('/app/ai-monitoring-review-evidence/{evidence}/download', [AiMonitoringReviewEvidenceController::class, 'download'])
        ->name('ai-monitoring-review-evidence.download');
    Route::get('/app/vendor-risk-review-evidence/{evidence}/download', [VendorRiskReviewEvidenceController::class, 'download'])
        ->name('vendor-risk-review-evidence.download');
    Route::get('/app/recovery-exercise-evidence/{evidence}/download', [RecoveryExerciseEvidenceController::class, 'download'])
        ->name('recovery-exercise-evidence.download');
    Route::get('/app/policy-attestation-evidence/{evidence}/download', [PolicyAttestationEvidenceController::class, 'download'])
        ->name('policy-attestation-evidence.download');
    Route::get('/app/policy-exception-monitoring-review-evidence/{evidence}/download', [PolicyExceptionMonitoringReviewEvidenceController::class, 'download'])
        ->name('policy-exception-monitoring-review-evidence.download');
    Route::get('/app/risk-governance-review-evidence/{evidence}/download', [RiskGovernanceReviewEvidenceController::class, 'download'])
        ->name('risk-governance-review-evidence.download');
    Route::get('/app/audit-closeout-reviews/{review}/report', [AuditCloseoutReportController::class, 'download'])
        ->name('audit-closeout-reviews.report');
    Route::get('/app/audit-finding-follow-up-evidence/{evidence}/download', [AuditFindingFollowUpEvidenceController::class, 'download'])
        ->name('audit-finding-follow-up-evidence.download');
    Route::get('/app/audit-procedure-execution-evidence/{evidence}/download', [AuditProcedureExecutionEvidenceController::class, 'download'])
        ->name('audit-procedure-execution-evidence.download');
    Route::get('/app/incident-task-event-evidence/{evidence}/download', [IncidentTaskEventEvidenceController::class, 'download'])
        ->name('incident-task-event-evidence.download');

    // Media proxy route for serving private S3/cloud storage files
    Route::get('/media/{path}', [MediaProxyController::class, 'show'])
        ->where('path', '.*')
        ->name('media.show');

    // Survey attachment download route
    Route::get('/survey-attachment/{attachment}/download', [SurveyAttachmentController::class, 'download'])
        ->name('survey-attachment.download');

});

// Central OIDC (Fynix HQ) — same standards as PPM: state CSRF, sub-first linking
Route::get('auth/sso/login', [SsoController::class, 'login'])->name('auth.sso.login');
Route::get('auth/sso/callback', [SsoController::class, 'callback'])->name('auth.sso.callback');

// Provider-specific Socialite adapters still land in IdentityService
Route::get('auth/{provider}/redirect', '\App\Http\Controllers\Auth\AuthController@redirectToProvider')->name('socialite.redirect');
Route::get('auth/{provider}/callback', '\App\Http\Controllers\Auth\AuthController@handleProviderCallback')->name('socialite.callback');

// Legacy public survey routes - redirect to new magic link URL
// (Surveys are now handled through the authenticated Vendor Panel with magic links)
Route::get('survey/{token}', function ($token) {
    // Find the survey by access token
    $survey = Survey::where('access_token', $token)->first();

    if (! $survey) {
        abort(404, 'Survey not found');
    }

    // Redirect to the new magic link URL
    return redirect($survey->getPublicUrl());
})->name('survey.show');

// Vendor Portal Magic Link Routes
Route::get('/portal/auth/magic-login/{vendorUser}', [VendorAuthController::class, 'magicLogin'])
    ->name('vendor.magic-login')
    ->middleware('signed');

// Survey-specific magic link - logs in vendor and redirects to survey
Route::get('/portal/survey/{survey}/respond', [VendorAuthController::class, 'surveyMagicLink'])
    ->name('vendor.survey.magic-link')
    ->middleware('signed');

// Vendor Survey Access Page (login/register flow - no auth required)
Route::get('/portal/survey-access', SurveyAccess::class)
    ->name('filament.vendor.pages.survey-access')
    ->middleware('signed');

// Vendor Document Download Route (requires vendor auth)
Route::middleware(['auth:vendor'])->group(function () {
    Route::get('/portal/document/{vendorDocument}/download', [VendorDocumentController::class, 'download'])
        ->name('vendor.document.download');
});

// Trust Center Routes (public)
Route::prefix('trust')->group(function () {
    // Public Trust Center home page
    Route::get('/', [TrustCenterController::class, 'index'])
        ->name('trust-center.index');

    // Public document download
    Route::get('/document/{document}/download', [TrustCenterController::class, 'downloadPublic'])
        ->name('trust-center.document.download');

    // Access request submission (rate limited to prevent spam)
    Route::post('/request-access', [TrustCenterController::class, 'requestAccess'])
        ->name('trust-center.request-access')
        ->middleware('throttle:5,1');

    // Protected access via magic link (signed URL)
    Route::get('/access/{accessRequest}', [TrustCenterController::class, 'protectedAccess'])
        ->name('trust-center.protected-access')
        ->middleware('signed');

    // Protected document download via magic link
    Route::get('/access/{accessRequest}/document/{document}/download', [TrustCenterController::class, 'downloadProtected'])
        ->name('trust-center.protected-download')
        ->middleware('signed');
});
