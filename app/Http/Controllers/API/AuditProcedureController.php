<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteAuditProcedureRequest;
use App\Http\Requests\ListAuditProceduresRequest;
use App\Http\Requests\ReviewAuditWorkpaperRequest;
use App\Http\Requests\StoreAuditProcedureRequest;
use App\Models\Audit;
use App\Models\AuditProcedure;
use App\Models\AuditProcedureExecution;
use App\Services\AuditProcedureManager;
use Illuminate\Http\JsonResponse;

class AuditProcedureController extends Controller
{
    public function index(ListAuditProceduresRequest $request, Audit $audit): JsonResponse
    {
        $this->authorize('view', $audit);
        $procedures = $audit->procedures()->with(['auditItem.auditable', 'assignee:id,name', 'creator:id,name', 'execution.executor:id,name', 'execution.review.reviewer:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25));

        return response()->json($procedures);
    }

    public function store(StoreAuditProcedureRequest $request, Audit $audit, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($audit, $request->user(), $request->validated())], 201);
    }

    public function execute(ExecuteAuditProcedureRequest $request, AuditProcedure $procedure, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->execute($procedure, $request->user(), $request->validated())], 201);
    }

    public function review(ReviewAuditWorkpaperRequest $request, AuditProcedureExecution $execution, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($execution, $request->user(), $request->validated())], 201);
    }
}
