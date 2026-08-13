<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseApiController extends Controller
{
    /**
     * The model class for this controller
     */
    protected string $modelClass;

    /**
     * The resource name for messages (e.g., 'Audits', 'Controls')
     */
    protected string $resourceName;

    /**
     * Relations to eager load on index
     */
    protected array $indexRelations = [];

    /**
     * Relations to eager load on show
     */
    protected array $showRelations = [];

    /**
     * Fields that can be searched
     */
    protected array $searchableFields = [];

    /**
     * Fields that can be sorted
     */
    protected array $sortableFields = ['id', 'created_at', 'updated_at'];

    /**
     * Default sort field
     */
    protected string $defaultSortField = 'created_at';

    /**
     * Default sort direction
     */
    protected string $defaultSortDirection = 'desc';

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', $this->modelClass);

        $query = $this->modelClass::query();

        // Eager load relationships
        if (! empty($this->indexRelations)) {
            $query->with($this->indexRelations);
        }

        // Allow custom query modifications
        $query = $this->applyIndexFilters($query, $request);

        // Search
        if ($request->has('search') && ! empty($this->searchableFields)) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                foreach ($this->searchableFields as $field) {
                    if (str_contains($field, '.')) {
                        // Handle relationship fields
                        $parts = explode('.', $field);
                        $relation = $parts[0];
                        $column = $parts[1];
                        $q->orWhereHas($relation, function ($subQuery) use ($column, $search) {
                            $subQuery->where($column, 'like', "%{$search}%");
                        });
                    } else {
                        $q->orWhere($field, 'like', "%{$search}%");
                    }
                }
            });
        }

        // Sorting
        $sortField = $request->input('sort', $this->defaultSortField);
        $sortDirection = $request->input('direction', $this->defaultSortDirection);

        if (in_array($sortField, $this->sortableFields) && in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);

        if ($request->boolean('no_pagination')) {
            $resources = $query->get();

            return response()->json([
                'data' => $resources,
            ], JsonResponse::HTTP_OK);
        }

        $resources = $query->paginate($perPage);

        return response()->json($resources, JsonResponse::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', $this->modelClass);

        $validatedData = $this->validateStore($request);

        $resource = $this->modelClass::create($validatedData);

        // Load relationships if specified
        if (! empty($this->showRelations)) {
            $resource->load($this->showRelations);
        }

        return response()->json([
            'message' => $this->resourceName.' created successfully',
            'data' => $resource,
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $query = $this->modelClass::query();

        if (! empty($this->showRelations)) {
            $query->with($this->showRelations);
        }

        $resource = $query->findOrFail($id);

        $this->authorize('view', $resource);

        return response()->json([
            'data' => $resource,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $resource = $this->modelClass::findOrFail($id);

        $this->authorize('update', $resource);

        $validatedData = $this->validateUpdate($request, $resource);

        $resource->update($validatedData);

        // Refresh and load relationships
        $resource->refresh();
        if (! empty($this->showRelations)) {
            $resource->load($this->showRelations);
        }

        return response()->json([
            'message' => $this->resourceName.' updated successfully',
            'data' => $resource,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $resource = $this->modelClass::findOrFail($id);

        $this->authorize('delete', $resource);

        $resource->delete();

        return response()->json([
            'message' => $this->resourceName.' deleted successfully',
        ], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Restore a soft-deleted resource.
     */
    public function restore(int $id): JsonResponse
    {
        $resource = $this->modelClass::withTrashed()->findOrFail($id);

        $this->authorize('restore', $resource);

        if (! $resource->trashed()) {
            return response()->json([
                'message' => 'Resource is not deleted',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $resource->restore();

        return response()->json([
            'message' => $this->resourceName.' restored successfully',
            'data' => $resource,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * Apply custom filters to the index query.
     * Override this method in child controllers for custom filtering logic.
     */
    protected function applyIndexFilters($query, Request $request)
    {
        return $query;
    }

    /**
     * Validate data for store operation.
     * Override this method in child controllers for custom validation.
     */
    protected function validateStore(Request $request): array
    {
        return $request->validate([]);
    }

    /**
     * Validate data for update operation.
     * Override this method in child controllers for custom validation.
     */
    protected function validateUpdate(Request $request, Model $resource): array
    {
        return $request->validate([]);
    }
}
