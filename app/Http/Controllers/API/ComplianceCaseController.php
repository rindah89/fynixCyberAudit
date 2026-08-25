<?php

namespace App\Http\Controllers\API;

use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\ComplianceCases\ComplianceCaseInterviewManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListComplianceCasesRequest;
use App\Http\Requests\RecordComplianceCaseEventRequest;
use App\Http\Requests\RecordComplianceCaseInterviewEventRequest;
use App\Http\Requests\ShowComplianceCaseRequest;
use App\Http\Requests\StoreComplianceCaseEvidenceRequest;
use App\Http\Requests\StoreComplianceCaseInterviewRequest;
use App\Http\Requests\StoreComplianceCaseRequest;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseInterview;
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
        return response()->json(['data' => $complianceCase->load(['opener:id,name', 'assignee:id,name'])->loadCount('events')]);
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
}
