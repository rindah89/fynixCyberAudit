<?php

namespace App\Http\Controllers\API;

use App\ComplianceCases\ComplianceCaseManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListComplianceCasesRequest;
use App\Http\Requests\RecordComplianceCaseEventRequest;
use App\Http\Requests\ShowComplianceCaseRequest;
use App\Http\Requests\StoreComplianceCaseRequest;
use App\Models\ComplianceCase;
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
}
