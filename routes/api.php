<?php

use App\Http\Controllers\API\AiGovernanceController;
use App\Http\Controllers\API\ApplicationController;
use App\Http\Controllers\API\AssetController;
use App\Http\Controllers\API\AuditCloseoutController;
use App\Http\Controllers\API\AuditController;
use App\Http\Controllers\API\AuditItemController;
use App\Http\Controllers\API\AuditEffortController;
use App\Http\Controllers\API\AuditProcedureController;
use App\Http\Controllers\API\AuditUniverseController;
use App\Http\Controllers\API\ChecklistController;
use App\Http\Controllers\API\ChecklistTemplateController;
use App\Http\Controllers\API\ContinuousControlTestingController;
use App\Http\Controllers\API\ControlController;
use App\Http\Controllers\API\DataRequestController;
use App\Http\Controllers\API\DataRequestResponseController;
use App\Http\Controllers\API\EvidenceAuthorizationController;
use App\Http\Controllers\API\ExecutiveAuthorityBindingController;
use App\Http\Controllers\API\FileAttachmentController;
use App\Http\Controllers\API\GovernanceIssueLifecycleController;
use App\Http\Controllers\API\ImplementationController;
use App\Http\Controllers\API\OperationalResilienceController;
use App\Http\Controllers\API\PolicyComplianceController;
use App\Http\Controllers\API\PolicyController;
use App\Http\Controllers\API\ProgramController;
use App\Http\Controllers\API\RegulatoryChangeController;
use App\Http\Controllers\API\RiskController;
use App\Http\Controllers\API\RiskPortfolioController;
use App\Http\Controllers\API\StandardController;
use App\Http\Controllers\API\SupportChangeEvidenceController;
use App\Http\Controllers\API\ThirdPartyRiskController;
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
Route::post('/evidence-authorizations', [EvidenceAuthorizationController::class, 'store'])->middleware('throttle:api')->name('evidence-authorizations.store');
Route::get('/evidence-authorizations/{authorization}', [EvidenceAuthorizationController::class, 'show'])->middleware('throttle:api')->name('evidence-authorizations.show');
Route::post('/evidence-authorizations/{authorization}/accept', [EvidenceAuthorizationController::class, 'accept'])->middleware(['auth:sanctum', 'throttle:api'])->name('evidence-authorizations.accept');
Route::post('/evidence-authorizations/{authorization}/reject', [EvidenceAuthorizationController::class, 'reject'])->middleware(['auth:sanctum', 'throttle:api'])->name('evidence-authorizations.reject');
Route::post('/evidence-authorizations/{authorization}/revoke', [EvidenceAuthorizationController::class, 'revoke'])->middleware(['auth:sanctum', 'throttle:api'])->name('evidence-authorizations.revoke');
Route::post('/evidence-authorizations/{authorization}/claims', [EvidenceAuthorizationController::class, 'claim'])->middleware('throttle:api')->name('evidence-authorizations.claim');
Route::post('/evidence-authorizations/{authorization}/consume', [EvidenceAuthorizationController::class, 'consume'])->middleware('throttle:api')->name('evidence-authorizations.consume');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function () {

    // RESTful API Resources with full CRUD operations
    Route::apiResource('users', UserController::class);
    Route::apiResource('standards', StandardController::class);
    Route::apiResource('controls', ControlController::class);
    Route::post('/controls/{control}/test-definitions', [ContinuousControlTestingController::class, 'store']);
    Route::post('/control-test-definitions/{definition}/execute', [ContinuousControlTestingController::class, 'execute']);
    Route::apiResource('implementations', ImplementationController::class);
    Route::post('/business-services', [OperationalResilienceController::class, 'storeService']);
    Route::post('/business-services/{service}/impact-analyses', [OperationalResilienceController::class, 'storeImpactAnalysis']);
    Route::post('/business-services/{service}/dependencies', [OperationalResilienceController::class, 'storeDependency']);
    Route::post('/business-services/{service}/recovery-plans', [OperationalResilienceController::class, 'storePlan']);
    Route::post('/recovery-plans/{plan}/exercises', [OperationalResilienceController::class, 'storeExercise']);
    Route::post('/recovery-exercises/{exercise}/complete', [OperationalResilienceController::class, 'completeExercise']);
    Route::apiResource('audits', AuditController::class);
    Route::get('/audits/{audit}/effort-budgets', [AuditEffortController::class, 'budgets']);
    Route::post('/audits/{audit}/effort-budgets', [AuditEffortController::class, 'storeBudget']);
    Route::get('/audits/{audit}/time-entries', [AuditEffortController::class, 'entries']);
    Route::post('/audits/{audit}/time-entries', [AuditEffortController::class, 'storeEntry']);
    Route::get('/audits/{audit}/effort-summary', [AuditEffortController::class, 'summary']);
    Route::post('/audit-time-entries/{entry}/reverse', [AuditEffortController::class, 'reverse']);
    Route::get('/audits/{audit}/procedures', [AuditProcedureController::class, 'index']);
    Route::post('/audits/{audit}/procedures', [AuditProcedureController::class, 'store']);
    Route::post('/audit-procedures/{procedure}/execute', [AuditProcedureController::class, 'execute']);
    Route::get('/audits/{audit}/closeouts', [AuditCloseoutController::class, 'index']);
    Route::post('/audits/{audit}/closeouts', [AuditCloseoutController::class, 'submit']);
    Route::post('/audit-closeout-submissions/{submission}/review', [AuditCloseoutController::class, 'review']);
    Route::get('/auditable-entities', [AuditUniverseController::class, 'entities']);
    Route::post('/auditable-entities', [AuditUniverseController::class, 'storeEntity']);
    Route::put('/auditable-entities/{entity}', [AuditUniverseController::class, 'updateEntity']);
    Route::post('/auditable-entities/{entity}/assessments', [AuditUniverseController::class, 'assess']);
    Route::get('/auditable-entities/{entity}/assessments', [AuditUniverseController::class, 'assessments']);
    Route::get('/audit-plans', [AuditUniverseController::class, 'plans']);
    Route::post('/audit-plans', [AuditUniverseController::class, 'storePlan']);
    Route::post('/audit-plans/{plan}/items', [AuditUniverseController::class, 'addItem']);
    Route::get('/audit-plans/{plan}/items', [AuditUniverseController::class, 'items']);
    Route::put('/audit-plans/{plan}/items/{item}', [AuditUniverseController::class, 'updateItem']);
    Route::delete('/audit-plans/{plan}/items/{item}', [AuditUniverseController::class, 'removeItem']);
    Route::post('/audit-plans/{plan}/approve', [AuditUniverseController::class, 'approve']);
    Route::post('/audit-plan-items/{item}/launch-engagement', [AuditUniverseController::class, 'launchEngagement']);
    Route::apiResource('audit-items', AuditItemController::class);
    Route::apiResource('programs', ProgramController::class);
    Route::apiResource('risks', RiskController::class);
    Route::put('/risks/{risk}/governance-profile', [RiskPortfolioController::class, 'profile']);
    Route::post('/risks/{risk}/governance-reviews', [RiskPortfolioController::class, 'review']);
    Route::post('/risks/{risk}/operational-loss-events', [RiskPortfolioController::class, 'recordLossEvent']);
    Route::get('/risks/{risk}/operational-loss-events', [RiskPortfolioController::class, 'lossEvents']);
    Route::post('/risks/{risk}/indicators', [RiskPortfolioController::class, 'storeIndicator']);
    Route::get('/risks/{risk}/indicators', [RiskPortfolioController::class, 'indicators']);
    Route::post('/risk-indicators/{indicator}/observations', [RiskPortfolioController::class, 'observeIndicator']);
    Route::get('/risk-indicators/{indicator}/observations', [RiskPortfolioController::class, 'indicatorObservations']);
    Route::put('/risk-indicators/{indicator}', [RiskPortfolioController::class, 'updateIndicator']);
    Route::post('/risks/{risk}/technology-exposure-assessments', [RiskPortfolioController::class, 'assessTechnologyExposure']);
    Route::get('/risks/{risk}/technology-exposure-assessments', [RiskPortfolioController::class, 'technologyExposureAssessments']);
    Route::put('/risks/{risk}/parent', [RiskPortfolioController::class, 'parent']);
    Route::get('/risks/{risk}/rollup', [RiskPortfolioController::class, 'rollup']);
    Route::post('/risks/{risk}/scenarios', [RiskPortfolioController::class, 'scenario']);
    Route::get('/risks/{risk}/scenarios', [RiskPortfolioController::class, 'scenarios']);
    Route::get('/enterprise-risk-scenarios/{scenario}', [RiskPortfolioController::class, 'showScenario']);
    Route::get('/governance-issues/{type}/{issue}', [GovernanceIssueLifecycleController::class, 'show']);
    Route::post('/governance-issues/{type}/{issue}/remediation', [GovernanceIssueLifecycleController::class, 'remediation']);
    Route::post('/governance-issues/{type}/{issue}/request-verification', [GovernanceIssueLifecycleController::class, 'requestVerification']);
    Route::post('/governance-issues/{type}/{issue}/close', [GovernanceIssueLifecycleController::class, 'close']);
    Route::post('/governance-issues/{type}/{issue}/reopen', [GovernanceIssueLifecycleController::class, 'reopen']);
    Route::apiResource('vendors', VendorController::class);
    Route::apiResource('applications', ApplicationController::class);
    Route::post('/ai-systems', [AiGovernanceController::class, 'storeSystem']);
    Route::post('/ai-systems/{system}/use-cases', [AiGovernanceController::class, 'storeUseCase']);
    Route::post('/ai-use-cases/{useCase}/assessments', [AiGovernanceController::class, 'storeAssessment']);
    Route::post('/ai-use-cases/{useCase}/controls', [AiGovernanceController::class, 'mapControl']);
    Route::post('/ai-use-cases/{useCase}/risks', [AiGovernanceController::class, 'mapRisk']);
    Route::post('/ai-use-cases/{useCase}/decisions', [AiGovernanceController::class, 'decide']);
    Route::post('/ai-use-cases/{useCase}/monitoring-reviews', [AiGovernanceController::class, 'monitor']);
    Route::post('/vendors/{vendor}/risk-assessments', [ThirdPartyRiskController::class, 'assess']);
    Route::post('/vendors/{vendor}/risks', [ThirdPartyRiskController::class, 'mapRisk']);
    Route::post('/vendors/{vendor}/risk-decisions', [ThirdPartyRiskController::class, 'decide']);
    Route::post('/vendors/{vendor}/risk-reviews', [ThirdPartyRiskController::class, 'review']);
    Route::get('/vendors/{vendor}/fourth-party-dependencies', [ThirdPartyRiskController::class, 'fourthPartyDependencies']);
    Route::post('/vendors/{vendor}/fourth-party-dependencies', [ThirdPartyRiskController::class, 'recordFourthPartyDependency']);
    Route::get('/third-party-risk/fourth-party-concentrations', [ThirdPartyRiskController::class, 'fourthPartyConcentrations']);
    Route::apiResource('assets', AssetController::class);
    Route::apiResource('policies', PolicyController::class);
    Route::post('/policies/{policy}/obligations', [PolicyComplianceController::class, 'store']);
    Route::post('/policies/{policy}/acknowledgement-campaigns', [PolicyComplianceController::class, 'launchAcknowledgementCampaign']);
    Route::get('/policy-acknowledgements/mine', [PolicyComplianceController::class, 'myAcknowledgements']);
    Route::get('/policy-acknowledgement-campaigns/{campaign}/report', [PolicyComplianceController::class, 'acknowledgementReport']);
    Route::post('/policy-acknowledgement-assignments/{assignment}/acknowledge', [PolicyComplianceController::class, 'acknowledge']);
    Route::post('/policy-acknowledgement-campaigns/{campaign}/close', [PolicyComplianceController::class, 'closeAcknowledgementCampaign']);
    Route::post('/regulatory-sources', [RegulatoryChangeController::class, 'storeSource']);
    Route::put('/regulatory-sources/{source}', [RegulatoryChangeController::class, 'updateSource']);
    Route::post('/regulatory-sources/{source}/requirements', [RegulatoryChangeController::class, 'storeRequirement']);
    Route::post('/regulatory-requirements/{requirement}/versions', [RegulatoryChangeController::class, 'publish']);
    Route::get('/regulatory-requirements/{requirement}/versions', [RegulatoryChangeController::class, 'versions']);
    Route::get('/regulatory-requirements/{requirement}/assessments', [RegulatoryChangeController::class, 'assessments']);
    Route::post('/regulatory-requirement-versions/{version}/assessments', [RegulatoryChangeController::class, 'assess']);
    Route::get('/regulatory-requirements', [RegulatoryChangeController::class, 'index']);
    Route::post('/policy-obligations/{obligation}/attest', [PolicyComplianceController::class, 'attest']);
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
