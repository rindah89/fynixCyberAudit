<?php

namespace App\Http\Controllers\API;

use App\ComplianceCases\ComplianceCaseClosureReportManager;
use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\ComplianceCases\ComplianceCaseInterviewManager;
use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcknowledgeComplianceCaseLegalHoldRequest;
use App\Http\Requests\GenerateComplianceCaseClosureReportRequest;
use App\Http\Requests\ListComplianceCaseClosureReportsRequest;
use App\Http\Requests\ListComplianceCaseInvestigationPlansRequest;
use App\Http\Requests\ListComplianceCaseInvestigationProcedureExecutionsRequest;
use App\Http\Requests\ListComplianceCaseInvestigationReportsRequest;
use App\Http\Requests\ListComplianceCasesRequest;
use App\Http\Requests\ListMyComplianceCaseLegalHoldsRequest;
use App\Http\Requests\RecordComplianceCaseEventRequest;
use App\Http\Requests\RecordComplianceCaseInterviewEventRequest;
use App\Http\Requests\ReleaseComplianceCaseLegalHoldRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationPlanRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationProcedureExecutionRequest;
use App\Http\Requests\ReviewComplianceCaseInvestigationReportRequest;
use App\Http\Requests\ShowComplianceCaseRequest;
use App\Http\Requests\StoreComplianceCaseEvidenceRequest;
use App\Http\Requests\StoreComplianceCaseInterviewRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationPlanRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationProcedureExecutionRequest;
use App\Http\Requests\StoreComplianceCaseInvestigationReportRequest;
use App\Http\Requests\StoreComplianceCaseLegalHoldRequest;
use App\Http\Requests\StoreComplianceCaseRequest;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldCustodian;
use Illuminate\Http\JsonResponse;

class ComplianceCaseController extends Controller
{
    public function index(ListComplianceCasesRequest $request): JsonResponse
    {
        $query = ComplianceCase::query()->with(['opener:id,name', 'assignee:id,name'])->withCount('events')->latest('id');
        if (! $request->user()->can('Manage Compliance Cases') && ! $request->user()->can('Read Compliance Cases')) {
            $query->where('assigned_to', $request->user()->id);
        }

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
}
