<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditEffortRequest;
use App\Http\Requests\ReverseAuditTimeEntryRequest;
use App\Http\Requests\StoreAuditEffortBudgetRequest;
use App\Http\Requests\StoreAuditTimeEntryRequest;
use App\Models\Audit;
use App\Models\AuditTimeEntry;
use App\Services\AuditEffortManager;
use Illuminate\Http\JsonResponse;

class AuditEffortController extends Controller
{
    public function budgets(ListAuditEffortRequest $request, Audit $audit): JsonResponse
    {
        return response()->json($audit->effortBudgets()->with(['procedure:id,code,title', 'user:id,name', 'setter:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function entries(ListAuditEffortRequest $request, Audit $audit): JsonResponse
    {
        return response()->json($audit->timeEntries()->with(['procedure:id,code,title', 'user:id,name', 'entrant:id,name', 'reversal:id,reverses_time_entry_id'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function summary(ListAuditEffortRequest $request, Audit $audit, AuditEffortManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->summary($audit)]);
    }

    public function storeBudget(StoreAuditEffortBudgetRequest $request, Audit $audit, AuditEffortManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->budget($audit, $request->user(), $request->validated())], 201);
    }

    public function storeEntry(StoreAuditTimeEntryRequest $request, Audit $audit, AuditEffortManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->record($audit, $request->user(), $request->validated())], 201);
    }

    public function reverse(ReverseAuditTimeEntryRequest $request, AuditTimeEntry $entry, AuditEffortManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->reverse($entry, $request->user(), $request->validated())], 201);
    }
}
