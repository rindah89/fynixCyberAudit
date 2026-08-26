<?php

namespace App\Http\Controllers\API;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\ComplianceCases\ComplianceCaseArchiveManager;
use App\ComplianceCases\ComplianceCaseClosureReportManager;
use App\ComplianceCases\ComplianceCaseClosureReportReviewManager;
use App\ComplianceCases\ComplianceCaseCommunicationManager;
use App\ComplianceCases\ComplianceCaseConflictManager;
use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\ComplianceCases\ComplianceCaseInterviewManager;
use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\ComplianceCases\ComplianceCaseMilestoneManager;
use App\ComplianceCases\ComplianceCasePortfolioManager;
use App\ComplianceCases\ComplianceCaseReopenManager;
use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcknowledgeComplianceCaseLegalHoldRequest;
use App\Http\Requests\CompleteComplianceCaseMilestoneRequest;
use App\Http\Requests\DecideComplianceCaseConflictRequest;
use App\Http\Requests\GenerateComplianceCaseClosureReportRequest;
use App\Http\Requests\ListComplianceCaseClosureReportsRequest;
use App\Http\Requests\ListComplianceCaseConflictsRequest;
use App\Http\Requests\ListComplianceCaseInvestigationPlansRequest;
use App\Http\Requests\ListComplianceCaseInvestigationProcedureExecutionsRequest;
use App\Http\Requests\ListComplianceCaseInvestigationReportsRequest;
use App\Http\Requests\ListComplianceCasesRequest;
use App\Http\Requests\ListMyComplianceCaseLegalHoldsRequest;
use App\Http\Requests\RecordComplianceCaseEventRequest;
use App\Http\Requests\RecordComplianceCaseInterviewEventRequest;
use App\Http\Requests\ReleaseComplianceCaseLegalHoldRequest;
use App\Http\Requests\ReviewComplianceCaseArchiveRequest;
use App\Http\Requests\ReviewComplianceCaseClosureReportRequest;
use App\Http\Requests\ReviewComplianceCaseDispositionRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationPlanRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationProcedureExecutionRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationReportRequest;
use App\Http\Requests\ReviewComplianceCaseReopenProposalRequest;
use App\Http\Requests\RevokeComplianceCaseAccessGrantRequest;
use App\Http\Requests\ShowComplianceCasePortfolioRequest;
use App\Http\Requests\ShowComplianceCaseRequest;
use App\Http\Requests\StoreComplianceCaseAccessGrantRequest;
use App\Http\Requests\StoreComplianceCaseArchiveRequest;
use App\Http\Requests\StoreComplianceCaseCommunicationRequest;
use App\Http\Requests\StoreComplianceCaseConflictRequest;
use App\Http\Requests\StoreComplianceCaseEvidenceRequest;
use App\Http\Requests\StoreComplianceCaseInterviewRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationPlanRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationProcedureExecutionRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationReportRequest;
use App\Http\Requests\StoreComplianceCaseLegalHoldRequest;
use App\Http\Requests\StoreComplianceCaseMilestoneRequest;
use App\Http\Requests\StoreComplianceCaseReopenProposalRequest;
use App\Http\Requests\StoreComplianceCaseRequest;
use App\Http\Requests\StoreComplianceCaseRetentionRequest;
use App\Http\Requests\WaiveComplianceCaseMilestoneRequest;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\ComplianceCaseArchiveManifest;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldCustodian;
use App\Models\ComplianceCaseMilestone;
use App\Models\ComplianceCaseReopenProposal;
use App\Models\ComplianceCaseRetentionClassification;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ComplianceCaseController extends Controller
{
    public function index(ListComplianceCasesRequest $request): JsonResponse
    {
        $query = ComplianceCase::query()->with(['opener:id,name', 'assignee:id,name'])->withCount('events')->latest('id');
        ComplianceCaseAccessGrantManager::scopeVisibleTo($query, $request->user());

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreComplianceCaseRequest $request, ComplianceCaseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->open($request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function show(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase): JsonResponse
    {
        return response()->json(['data' => $complianceCase->load(['opener:id,name', 'assignee:id,name'])->loadCount('events')
            ->append(['investigation_planning_governance_status', 'investigation_reporting_governance_status'])]);
    }

    public function events(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase): JsonResponse
    {
        return response()->json($complianceCase->events()->with('actor:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function record(RecordComplianceCaseEventRequest $request, ComplianceCase $complianceCase, ComplianceCaseManager $manager): JsonResponse
    {
        $event = $manager->record($request->user(), $complianceCase, $request->validated());

        return response()->json(['data' => $event, 'case' => $complianceCase->refresh()->load(['opener:id,name', 'assignee:id,name'])]);
    }

    public function evidence(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseEvidenceManager $manager): JsonResponse
    {
        $history = $complianceCase->evidenceSubmissions()->with(['actor:id,name,email', 'evidence.attachment.audit.members', 'evidence.attachment.dataRequestResponse.dataRequest.audit.members'])
            ->paginate($request->integer('per_page', 50));
        $history->setCollection($manager->visibleSubmissions($history->getCollection(), $request->user()));

        return response()->json($history);
    }

    public function actionIssues(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase): JsonResponse
    {
        return response()->json($complianceCase->actionIssues()->with([
            'owner:id,name,email', 'opener:id,name,email', 'event.actor:id,name,email',
            'lifecycle.remediationTask', 'lifecycle.verifier:id,name,email', 'lifecycle.closer:id,name,email',
            'lifecycle.transitions.actor:id,name,email', 'lifecycle.closureEvidence.linkedBy:id,name,email',
        ])->paginate($request->integer('per_page', 50)));
    }

    public function interviews(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase): JsonResponse
    {
        return response()->json($complianceCase->interviews()->with([
            'subjectUser:id,name,email', 'interviewer:id,name,email', 'events.actor:id,name,email',
        ])->paginate($request->integer('per_page', 50)));
    }

    public function scheduleInterview(StoreComplianceCaseInterviewRequest $request, ComplianceCase $complianceCase, ComplianceCaseInterviewManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->schedule($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function recordInterview(RecordComplianceCaseInterviewEventRequest $request, ComplianceCase $complianceCase, ComplianceCaseInterview $interview, ComplianceCaseInterviewManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->record($request->user(), $complianceCase, $interview, $request->validated())]);
    }

    public function storeEvidence(StoreComplianceCaseEvidenceRequest $request, ComplianceCase $complianceCase, ComplianceCaseEvidenceManager $manager): JsonResponse
    {
        $submission = $manager->submit($request->user(), $complianceCase, $request->validated());
        $visible = $manager->visibleSubmissions(collect([$submission]), $request->user())->first();

        return response()->json(['data' => $visible], JsonResponse::HTTP_CREATED);
    }

    public function conflicts(ListComplianceCaseConflictsRequest $request, ComplianceCase $complianceCase, ComplianceCaseConflictManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function declareConflict(StoreComplianceCaseConflictRequest $request, ComplianceCase $complianceCase, ComplianceCaseConflictManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->declare($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function decideConflict(DecideComplianceCaseConflictRequest $request, ComplianceCaseConflictDeclaration $declaration, ComplianceCaseConflictManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($request->user(), $declaration, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function milestones(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseMilestoneManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function defineMilestone(StoreComplianceCaseMilestoneRequest $request, ComplianceCase $complianceCase, ComplianceCaseMilestoneManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function completeMilestone(CompleteComplianceCaseMilestoneRequest $request, ComplianceCaseMilestone $milestone, ComplianceCaseMilestoneManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->complete($request->user(), $milestone, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function waiveMilestone(WaiveComplianceCaseMilestoneRequest $request, ComplianceCaseMilestone $milestone, ComplianceCaseMilestoneManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->waive($request->user(), $milestone, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function retentionClassifications(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseRetentionManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function classifyRetention(StoreComplianceCaseRetentionRequest $request, ComplianceCase $complianceCase, ComplianceCaseRetentionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->classify($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewDisposition(ReviewComplianceCaseDispositionRequest $request, ComplianceCaseRetentionClassification $classification, ComplianceCaseRetentionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $classification, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function accessGrants(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseAccessGrantManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function grantAccess(StoreComplianceCaseAccessGrantRequest $request, ComplianceCase $complianceCase, ComplianceCaseAccessGrantManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->grant($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function revokeAccess(RevokeComplianceCaseAccessGrantRequest $request, ComplianceCaseAccessGrant $grant, ComplianceCaseAccessGrantManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->revoke($request->user(), $grant, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function communications(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseCommunicationManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function recordCommunication(StoreComplianceCaseCommunicationRequest $request, ComplianceCase $complianceCase, ComplianceCaseCommunicationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->record($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reopenProposals(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseReopenManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function proposeReopen(StoreComplianceCaseReopenProposalRequest $request, ComplianceCase $complianceCase, ComplianceCaseReopenManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->propose($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewReopen(ReviewComplianceCaseReopenProposalRequest $request, ComplianceCaseReopenProposal $proposal, ComplianceCaseReopenManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $proposal, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function archiveManifests(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseArchiveManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $complianceCase, $request->integer('per_page', 50)));
    }

    public function generateArchive(StoreComplianceCaseArchiveRequest $request, ComplianceCase $complianceCase, ComplianceCaseArchiveManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->generate($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewArchive(ReviewComplianceCaseArchiveRequest $request, ComplianceCaseArchiveManifest $archive, ComplianceCaseArchiveManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $archive, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function portfolio(ShowComplianceCasePortfolioRequest $request, ComplianceCasePortfolioManager $manager): Response
    {
        $filters = $request->safe()->only(['opened_from', 'opened_to']);
        if ($request->input('format') === 'csv') {
            return $manager->downloadCsv($request->user(), $filters);
        }

        return response()->json(['data' => $manager->summarize($request->user(), $filters)]);
    }

    public function legalHolds(ShowComplianceCaseRequest $request, ComplianceCase $complianceCase, ComplianceCaseLegalHoldManager $manager): JsonResponse
    {
        return response()->json($complianceCase->legalHolds()->with($manager->relations())->paginate($request->integer('per_page', 50)));
    }

    public function issueLegalHold(StoreComplianceCaseLegalHoldRequest $request, ComplianceCase $complianceCase, ComplianceCaseLegalHoldManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->issue($request->user(), $complianceCase, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function acknowledgeLegalHold(AcknowledgeComplianceCaseLegalHoldRequest $request, ComplianceCaseLegalHold $legalHold, ComplianceCaseLegalHoldManager $manager): JsonResponse
    {
        $acknowledgement = $manager->acknowledge($request->user(), $legalHold, $request->validated());

        return response()->json(['data' => [
            'id' => $acknowledgement->id, 'acknowledged_at' => $acknowledgement->acknowledged_at,
            'statement' => $acknowledgement->statement, 'comment' => $acknowledgement->comment,
            'fingerprint' => $acknowledgement->fingerprint,
        ]], JsonResponse::HTTP_CREATED);
    }

    public function myLegalHolds(ListMyComplianceCaseLegalHoldsRequest $request): JsonResponse
    {
        $history = ComplianceCaseLegalHoldCustodian::query()->where('user_id', $request->user()->id)
            ->with(['legalHold.release:id,compliance_case_legal_hold_id,released_at,fingerprint', 'acknowledgement:id,compliance_case_legal_hold_custodian_id,acknowledged_at,fingerprint'])
            ->latest('id')->paginate($request->integer('per_page', 50));
        $history->setCollection($history->getCollection()->map(fn (ComplianceCaseLegalHoldCustodian $custodian): array => [
            'id' => $custodian->id,
            'legal_hold' => [
                'id' => $custodian->legalHold->id, 'reference' => $custodian->legalHold->reference,
                'scope' => $custodian->legalHold->scope, 'systems' => $custodian->legalHold->systems,
                'data_categories' => $custodian->legalHold->data_categories,
                'legal_basis_reference' => $custodian->legalHold->legal_basis_reference,
                'preservation_start_at' => $custodian->legalHold->preservation_start_at,
                'issued_at' => $custodian->legalHold->issued_at, 'fingerprint' => $custodian->legalHold->fingerprint,
                'released_at' => $custodian->legalHold->release?->released_at,
                'release_fingerprint' => $custodian->legalHold->release?->fingerprint,
            ],
            'acknowledgement' => $custodian->acknowledgement?->only(['id', 'acknowledged_at', 'fingerprint']),
        ]));

        return response()->json($history);
    }

    public function releaseLegalHold(ReleaseComplianceCaseLegalHoldRequest $request, ComplianceCase $complianceCase, ComplianceCaseLegalHold $legalHold, ComplianceCaseLegalHoldManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->release($request->user(), $complianceCase, $legalHold, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function investigationPlans(ListComplianceCaseInvestigationPlansRequest $request, ComplianceCase $case, ComplianceCaseInvestigationPlanManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $case, $request->integer('per_page', 50)));
    }

    public function submitInvestigationPlan(StoreComplianceCaseInvestigationPlanRequest $request, ComplianceCase $case, ComplianceCaseInvestigationPlanManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($request->user(), $case, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewInvestigationPlan(ReviewComplianceCaseInvestigationPlanRequest $request, ComplianceCaseInvestigationPlan $plan, ComplianceCaseInvestigationPlanManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $plan, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function investigationProcedureExecutions(ListComplianceCaseInvestigationProcedureExecutionsRequest $request, ComplianceCase $case, ComplianceCaseInvestigationProcedureExecutionManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $case, $request->integer('per_page', 50)));
    }

    public function recordInvestigationProcedureExecution(StoreComplianceCaseInvestigationProcedureExecutionRequest $request, ComplianceCase $case, ComplianceCaseInvestigationProcedureExecutionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->record($request->user(), $case, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewInvestigationProcedureExecution(ReviewComplianceCaseInvestigationProcedureExecutionRequest $request, ComplianceCaseInvestigationProcedureExecution $execution, ComplianceCaseInvestigationProcedureExecutionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $execution, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function investigationReports(ListComplianceCaseInvestigationReportsRequest $request, ComplianceCase $case, ComplianceCaseInvestigationReportManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $case, $request->integer('per_page', 50)));
    }

    public function submitInvestigationReport(StoreComplianceCaseInvestigationReportRequest $request, ComplianceCase $case, ComplianceCaseInvestigationReportManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($request->user(), $case, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewInvestigationReport(ReviewComplianceCaseInvestigationReportRequest $request, ComplianceCaseInvestigationReport $report, ComplianceCaseInvestigationReportManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $report, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function closureReports(ListComplianceCaseClosureReportsRequest $request, ComplianceCase $case, ComplianceCaseClosureReportManager $manager): JsonResponse
    {
        return response()->json($manager->history($request->user(), $case, $request->integer('per_page', 50)));
    }

    public function generateClosureReport(GenerateComplianceCaseClosureReportRequest $request, ComplianceCase $case, ComplianceCaseClosureReportManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->generate($request->user(), $case, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewClosureReport(ReviewComplianceCaseClosureReportRequest $request, ComplianceCaseClosureReport $report, ComplianceCaseClosureReportReviewManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $report, $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
