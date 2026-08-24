<?php

namespace App\Http\Controllers\API;

use App\Access\FileAccess;
use App\Enums\IncidentPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListIncidentsRequest;
use App\Http\Requests\ListIncidentTaskEventsRequest;
use App\Http\Requests\RecordIncidentTaskEventRequest;
use App\Http\Requests\ShowIncidentRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\TransitionIncidentPhaseRequest;
use App\Incidents\IncidentDesk;
use App\Models\Incident;
use App\Models\IncidentPlaybook;
use App\Models\IncidentTask;
use App\Models\IncidentTaskEvent;
use App\Models\User;
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
        $incident->load([
            'lead:id,name', 'reporter:id,name',
            'tasks' => fn ($query) => $query->with('assignee:id,name')->withCount('events'),
            'phaseTransitions.actor:id,name',
        ]);

        return response()->json(['data' => $incident]);
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

    public function taskEvent(RecordIncidentTaskEventRequest $request, IncidentTask $task, IncidentDesk $desk): JsonResponse
    {
        $event = $desk->recordTaskEvent($request->user(), $task, $request->validated());

        return response()->json(['data' => $this->visibleEvent($event, $request->user()), 'task' => $task->refresh()->load('assignee:id,name')], JsonResponse::HTTP_CREATED);
    }

    public function taskEvents(ListIncidentTaskEventsRequest $request, IncidentTask $task): JsonResponse
    {
        $events = $task->events()->with([
            'actor:id,name', 'evidence.linkedBy:id,name', 'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ])->paginate($request->integer('per_page', 50));
        $events->getCollection()->transform(fn (IncidentTaskEvent $event) => $this->visibleEvent($event, $request->user()));

        return response()->json($events);
    }

    private function visibleEvent(IncidentTaskEvent $event, User $actor): IncidentTaskEvent
    {
        $event->loadMissing([
            'actor:id,name', 'evidence.linkedBy:id,name', 'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]);
        $visible = clone $event;
        $visible->setRelation('evidence', $event->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values());

        return $visible;
    }
}
