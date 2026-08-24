<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListGovernedModelsRequest;
use App\Http\Requests\ReviseGovernedModelRequest;
use App\Http\Requests\ShowGovernedModelRequest;
use App\Http\Requests\StoreGovernedModelRequest;
use App\Http\Requests\StoreModelValidationRequest;
use App\ModelRisk\ModelRiskManager;
use App\Models\GovernedModel;
use Illuminate\Http\JsonResponse;

class ModelRiskController extends Controller
{
    public function index(ListGovernedModelsRequest $request): JsonResponse
    {
        $q = GovernedModel::query()->with(['owner:id,name', 'developer:id,name', 'latestVersion', 'latestValidation'])->withCount(['versions', 'validations'])->latest('id');
        if (! $request->user()->can('Read Model Risk') && ! $request->user()->can('Manage Model Risk') && ! $request->user()->can('Validate Models')) {
            $q->where(fn ($x) => $x->where('owner_id', $request->user()->id)->orWhere('developer_id', $request->user()->id));
        }

        return response()->json($q->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreGovernedModelRequest $request, ModelRiskManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->register($request->user(), $request->validated())], 201);
    }

    public function show(ShowGovernedModelRequest $request, GovernedModel $governedModel): JsonResponse
    {
        return response()->json(['data' => $governedModel->load(['owner:id,name,email', 'developer:id,name,email', 'latestVersion', 'latestValidation'])->loadCount(['versions', 'validations'])]);
    }

    public function revise(ReviseGovernedModelRequest $request, GovernedModel $governedModel, ModelRiskManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->revise($request->user(), $governedModel, $request->validated()), 'model' => $governedModel->refresh()->load(['owner:id,name', 'developer:id,name'])]);
    }

    public function validateModel(StoreModelValidationRequest $request, GovernedModel $governedModel, ModelRiskManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->validate($request->user(), $governedModel, $request->validated())], 201);
    }

    public function versions(ShowGovernedModelRequest $request, GovernedModel $governedModel): JsonResponse
    {
        return response()->json($governedModel->versions()->with('actor:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function validations(ShowGovernedModelRequest $request, GovernedModel $governedModel): JsonResponse
    {
        return response()->json($governedModel->validations()->with(['validator:id,name', 'modelVersion:id,version,fingerprint'])->paginate($request->integer('per_page', 50)));
    }
}
