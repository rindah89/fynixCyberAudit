<?php

namespace App\Http\Controllers\API;

use App\Esg\EsgDisclosureManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEsgDisclosuresRequest;
use App\Http\Requests\ShowEsgDisclosureRequest;
use App\Http\Requests\ShowEsgKpiObservationRequest;
use App\Http\Requests\StoreEsgDataValidationRequest;
use App\Http\Requests\StoreEsgDisclosureDecisionRequest;
use App\Http\Requests\StoreEsgDisclosureRequest;
use App\Models\EsgDisclosure;
use App\Models\EsgKpiObservation;
use Illuminate\Http\JsonResponse;

class EsgDisclosureController extends Controller
{
    public function validations(ShowEsgKpiObservationRequest $request, EsgKpiObservation $observation): JsonResponse
    {
        return response()->json($observation->validations()->paginate($request->integer('per_page', 50)));
    }

    public function validateData(StoreEsgDataValidationRequest $request, EsgKpiObservation $observation, EsgDisclosureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->validateData($request->user(), $observation, $request->validated())], 201);
    }

    public function index(ListEsgDisclosuresRequest $request): JsonResponse
    {
        return response()->json(EsgDisclosure::query()->with(['preparer:id,name', 'decisions.decider:id,name'])->latest('id')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreEsgDisclosureRequest $request, EsgDisclosureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->prepare($request->user(), $request->validated())], 201);
    }

    public function show(ShowEsgDisclosureRequest $request, EsgDisclosure $disclosure): JsonResponse
    {
        return response()->json(['data' => $disclosure->load(['preparer:id,name,email', 'validations.validator:id,name', 'decisions.decider:id,name'])]);
    }

    public function decisions(ShowEsgDisclosureRequest $request, EsgDisclosure $disclosure): JsonResponse
    {
        return response()->json($disclosure->decisions()->paginate($request->integer('per_page', 50)));
    }

    public function decide(StoreEsgDisclosureDecisionRequest $request, EsgDisclosure $disclosure, EsgDisclosureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($request->user(), $disclosure, $request->validated())], 201);
    }
}
