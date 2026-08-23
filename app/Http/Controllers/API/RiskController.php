<?php

namespace App\Http\Controllers\API;

use App\Enums\RiskDomain;
use App\Enums\RiskStatus;
use App\Models\Risk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskController extends BaseApiController
{
    protected string $modelClass = Risk::class;

    protected string $resourceName = 'Risks';

    protected array $showRelations = ['implementations'];

    protected array $searchableFields = ['code', 'name', 'description'];

    protected array $sortableFields = ['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk', 'created_at', 'updated_at'];

    protected function validateStore(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:255|unique:risks,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'domain' => ['required', Rule::enum(RiskDomain::class)],
            'status' => ['nullable', Rule::enum(RiskStatus::class)],
            'inherent_likelihood' => 'required|integer|between:1,5',
            'inherent_impact' => 'required|integer|between:1,5',
            'residual_likelihood' => 'required|integer|between:1,5',
            'residual_impact' => 'required|integer|between:1,5',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    protected function validateUpdate(Request $request, $resource): array
    {
        return $request->validate([
            'code' => 'sometimes|string|max:255|unique:risks,code,'.$resource->id,
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'domain' => ['sometimes', Rule::enum(RiskDomain::class)],
            'status' => ['sometimes', Rule::enum(RiskStatus::class)],
            'inherent_likelihood' => 'sometimes|integer|between:1,5',
            'inherent_impact' => 'sometimes|integer|between:1,5',
            'residual_likelihood' => 'sometimes|integer|between:1,5',
            'residual_impact' => 'sometimes|integer|between:1,5',
            'is_active' => 'sometimes|boolean',
        ]);
    }
}
