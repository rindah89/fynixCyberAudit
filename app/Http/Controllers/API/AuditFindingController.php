<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditFindingsRequest;
use App\Http\Requests\ShowAuditFindingRequest;
use App\Http\Requests\StoreAuditFindingRequest;
use App\Http\Requests\StoreAuditManagementResponseRequest;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Services\AuditFindingManager;
use Illuminate\Http\JsonResponse;

class AuditFindingController extends Controller
{
    public function index(ListAuditFindingsRequest $request, Audit $audit): JsonResponse
    {
        return response()->json($audit->governedFindings()->with(['auditItem.auditable', 'accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreAuditFindingRequest $request, Audit $audit, AuditFindingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->raise($audit, $request->user(), $request->validated())], 201);
    }

    public function show(ShowAuditFindingRequest $request, AuditFinding $finding): JsonResponse
    {
        return response()->json(['data' => $finding->load(['accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name'])]);
    }

    public function respond(StoreAuditManagementResponseRequest $request, AuditFinding $finding, AuditFindingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->respond($finding, $request->user(), $request->validated())], 201);
    }
}
