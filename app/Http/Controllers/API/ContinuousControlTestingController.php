<?php

namespace App\Http\Controllers\API;

use App\ContinuousControlTesting\ControlTestRunner;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteControlTestRequest;
use App\Http\Requests\StoreControlTestDefinitionRequest;
use App\Models\Control;
use App\Models\ControlTestDefinition;
use Illuminate\Http\JsonResponse;

class ContinuousControlTestingController extends Controller
{
    public function store(StoreControlTestDefinitionRequest $request, Control $control): JsonResponse
    {
        $definition = $control->testDefinitions()->create($request->validated());

        return response()->json(['data' => $definition->load(['owner', 'implementation'])], JsonResponse::HTTP_CREATED);
    }

    public function execute(ExecuteControlTestRequest $request, ControlTestDefinition $definition, ControlTestRunner $runner): JsonResponse
    {
        $data = $request->validated();

        $execution = $runner->execute(
            $definition,
            $request->user(),
            $data['observed_value'],
            $data['notes'] ?? null,
            $data['evidence_reference'] ?? null,
        );

        return response()->json(['data' => $execution, 'definition' => $definition->refresh()], JsonResponse::HTTP_CREATED);
    }
}
