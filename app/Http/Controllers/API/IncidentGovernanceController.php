<?php

namespace App\Http\Controllers\API;

use App\Enums\IncidentPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListIncidentsRequest;
use App\Http\Requests\ShowIncidentRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\TransitionIncidentPhaseRequest;
use App\Incidents\IncidentDesk;
use App\Models\Incident;
use App\Models\IncidentPlaybook;
use Illuminate\Http\JsonResponse;

class IncidentGovernanceController extends Controller
{
    public function index(ListIncidentsRequest $request): JsonResponse
    {
        $incidents = Incident::query()
            ->with(['lead:id,name', 'reporter:id,name'])
            ->withCount('phaseTransitions')
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($incidents);
    }

    public function show(ShowIncidentRequest $request, Incident $incident): JsonResponse
    {
        return response()->json(['data' => $incident->load([
            'lead:id,name', 'reporter:id,name', 'tasks', 'phaseTransitions.actor:id,name',
        ])]);
    }

    public function store(StoreIncidentRequest $request, IncidentDesk $desk): JsonResponse
    {
        $data = $request->validated();
        $playbook = IncidentPlaybook::query()->findOrFail($data['incident_playbook_id']);
        unset($data['incident_playbook_id']);
        $incident = $desk->createFromPlaybook($request->user(), $playbook, $data);

        return response()->json(['data' => $incident], JsonResponse::HTTP_CREATED);
    }

    public function transition(TransitionIncidentPhaseRequest $request, Incident $incident, IncidentDesk $desk): JsonResponse
    {
        $updated = $desk->advancePhase(
            $request->user(),
            $incident,
            $request->enum('phase', IncidentPhase::class),
            $request->string('summary')->toString(),
        );

        return response()->json(['data' => $updated]);
    }
}
