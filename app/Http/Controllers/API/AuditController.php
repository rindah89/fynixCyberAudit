<?php

namespace App\Http\Controllers\API;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends BaseApiController
{
    protected string $modelClass = Audit::class;

    protected string $resourceName = 'Audits';

    protected array $indexRelations = ['manager', 'standard'];

    protected array $showRelations = ['manager', 'standard', 'auditItems', 'engagementBaseline.launcher', 'engagementBaseline.planItem.plan', 'closeoutSubmissions.submitter', 'closeoutSubmissions.review.reviewer'];

    protected array $searchableFields = ['title', 'description', 'audit_type'];

    protected array $sortableFields = ['id', 'title', 'audit_type', 'status', 'start_date', 'end_date', 'created_at', 'updated_at'];

    /**
     * Display the specified audit resource.
     *
     * Returns a single audit. If the `with_details` query parameter is set to true, returns the audit with all related AuditItems (with their Controls, Implementations, DataRequests, and DataRequestResponses), as well as DataRequests (with their DataRequestResponses).
     *
     * @group Audit
     *
     * @urlParam id int required The ID of the audit. Example: 1
     *
     * @queryParam with_details boolean Return all related audit items, controls, implementations, data requests, and responses. Example: true
     *
     * @response scenario=basic {"id": 1, "title": "Q2 Audit", ...}
     * @response scenario=with_details {"id": 1, "title": "Q2 Audit", "audit_items": [{"id": 10, "control": {...}, "implementation": {...}, "data_requests": [{"id": 100, "responses": [{...}]}]}], "data_request": [{"id": 200, "responses": [{...}]}]}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $query = $this->modelClass::query();

        // Support the legacy with_details parameter (server-defined relations only)
        if ($request->query('with_details')) {
            $query->with([
                'auditItems.auditable',
                'auditItems.dataRequests.responses',
                'dataRequest.responses',
                'manager',
                'standard',
                'engagementBaseline.launcher',
                'engagementBaseline.planItem.plan',
                'closeoutSubmissions.submitter',
                'closeoutSubmissions.review.reviewer',
            ]);
        } elseif (! empty($this->showRelations)) {
            $query->with($this->showRelations);
        }

        $resource = $query->findOrFail($id);

        $this->authorize('view', $resource);

        return response()->json([
            'data' => $resource,
        ], JsonResponse::HTTP_OK);
    }

    protected function validateStore(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'audit_type' => 'required|string|in:controls,implementations',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'manager_id' => 'nullable|exists:users,id',
            'sid' => 'nullable|exists:standards,id',
        ]);
    }

    protected function validateUpdate(Request $request, Model $resource): array
    {
        return $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'audit_type' => 'sometimes|string|in:controls,implementations',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'manager_id' => 'nullable|exists:users,id',
            'sid' => 'nullable|exists:standards,id',
        ]);
    }
}
