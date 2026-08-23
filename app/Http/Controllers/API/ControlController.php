<?php

namespace App\Http\Controllers\API;

use App\Models\Control;
use Illuminate\Http\Request;

class ControlController extends BaseApiController
{
    protected string $modelClass = Control::class;

    protected string $resourceName = 'Controls';

    protected array $indexRelations = ['standard'];

    protected array $showRelations = ['standard', 'implementations', 'controlOwner', 'testDefinitions.owner', 'testDefinitions.latestExecution.finding'];

    protected array $searchableFields = ['identifier', 'title', 'description', 'standard.code', 'standard.title'];

    protected array $sortableFields = ['id', 'identifier', 'title', 'status', 'effectiveness', 'created_at', 'updated_at'];

    protected function validateStore(Request $request): array
    {
        return $request->validate([
            'standard_id' => 'required|exists:standards,id',
            'identifier' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string',
            'effectiveness' => 'nullable|string',
            'type' => 'nullable|string',
            'category' => 'nullable|string',
            'enforcement' => 'nullable|string',
            'control_owner_id' => 'nullable|exists:users,id',
        ]);
    }

    protected function validateUpdate(Request $request, $resource): array
    {
        return $request->validate([
            'standard_id' => 'sometimes|exists:standards,id',
            'identifier' => 'sometimes|string|max:255',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string',
            'effectiveness' => 'nullable|string',
            'type' => 'nullable|string',
            'category' => 'nullable|string',
            'enforcement' => 'nullable|string',
            'control_owner_id' => 'nullable|exists:users,id',
        ]);
    }
}
