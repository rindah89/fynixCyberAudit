<?php

use App\Http\Controllers\API\ApplicationController;
use App\Http\Controllers\API\AssetController;
use App\Http\Controllers\API\AuditController;
use App\Http\Controllers\API\AuditItemController;
use App\Http\Controllers\API\ChecklistController;
use App\Http\Controllers\API\ChecklistTemplateController;
use App\Http\Controllers\API\ControlController;
use App\Http\Controllers\API\DataRequestController;
use App\Http\Controllers\API\DataRequestResponseController;
use App\Http\Controllers\API\ExecutiveAuthorityBindingController;
use App\Http\Controllers\API\FileAttachmentController;
use App\Http\Controllers\API\ImplementationController;
use App\Http\Controllers\API\PolicyController;
use App\Http\Controllers\API\ProgramController;
use App\Http\Controllers\API\RiskController;
use App\Http\Controllers\API\StandardController;
use App\Http\Controllers\API\SupportChangeEvidenceController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\VendorController;
use App\Suite\SuiteInboundController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/suite/events', [SuiteInboundController::class, 'store'])
    ->middleware('throttle:suite-inbound');
Route::get('/suite/ready', [SuiteInboundController::class, 'ready']);
Route::post('/suite/executive-authority-bindings', [ExecutiveAuthorityBindingController::class, 'store'])->middleware('throttle:api');
Route::post('/support-change-evidence', [SupportChangeEvidenceController::class, 'store'])->middleware('throttle:api')->name('support-change-evidence.store');
Route::get('/support-change-evidence/{acceptance}', [SupportChangeEvidenceController::class, 'show'])->middleware('throttle:api')->name('support-change-evidence.show');
Route::post('/support-change-evidence/{acceptance}/accept', [SupportChangeEvidenceController::class, 'accept'])->middleware(['auth:sanctum', 'throttle:api'])->name('support-change-evidence.accept');
Route::post('/support-change-evidence/{acceptance}/reject', [SupportChangeEvidenceController::class, 'reject'])->middleware(['auth:sanctum', 'throttle:api'])->name('support-change-evidence.reject');
Route::post('/support-change-evidence/{acceptance}/revoke', [SupportChangeEvidenceController::class, 'revoke'])->middleware(['auth:sanctum', 'throttle:api'])->name('support-change-evidence.revoke');
Route::post('/support-change-evidence/{acceptance}/consume', [SupportChangeEvidenceController::class, 'consume'])->middleware('throttle:api')->name('support-change-evidence.consume');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function () {

    // RESTful API Resources with full CRUD operations
    Route::apiResource('users', UserController::class);
    Route::apiResource('standards', StandardController::class);
    Route::apiResource('controls', ControlController::class);
    Route::apiResource('implementations', ImplementationController::class);
    Route::apiResource('audits', AuditController::class);
    Route::apiResource('audit-items', AuditItemController::class);
    Route::apiResource('programs', ProgramController::class);
    Route::apiResource('risks', RiskController::class);
    Route::apiResource('vendors', VendorController::class);
    Route::apiResource('applications', ApplicationController::class);
    Route::apiResource('assets', AssetController::class);
    Route::apiResource('policies', PolicyController::class);
    Route::apiResource('data-requests', DataRequestController::class);
    Route::apiResource('data-request-responses', DataRequestResponseController::class);
    Route::apiResource('file-attachments', FileAttachmentController::class);
    Route::apiResource('checklists', ChecklistController::class);
    Route::apiResource('checklist-templates', ChecklistTemplateController::class);

    // Checklist approval
    Route::post('/checklists/{id}/approve', [ChecklistController::class, 'approve']);

    // Restore soft-deleted resources
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);
    Route::post('/standards/{id}/restore', [StandardController::class, 'restore']);
    Route::post('/controls/{id}/restore', [ControlController::class, 'restore']);
    Route::post('/implementations/{id}/restore', [ImplementationController::class, 'restore']);
    Route::post('/audits/{id}/restore', [AuditController::class, 'restore']);
    Route::post('/audit-items/{id}/restore', [AuditItemController::class, 'restore']);
    Route::post('/programs/{id}/restore', [ProgramController::class, 'restore']);
    Route::post('/risks/{id}/restore', [RiskController::class, 'restore']);
    Route::post('/vendors/{id}/restore', [VendorController::class, 'restore']);
    Route::post('/applications/{id}/restore', [ApplicationController::class, 'restore']);
    Route::post('/assets/{id}/restore', [AssetController::class, 'restore']);
    Route::post('/policies/{id}/restore', [PolicyController::class, 'restore']);

});
