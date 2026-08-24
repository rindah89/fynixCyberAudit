<?php

namespace App\Http\Controllers\API;

use App\Access\FileAccess;
use App\Enums\IncidentPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListIncidentAffectedEntitiesRequest;
use App\Http\Requests\ListIncidentLessonEventsRequest;
use App\Http\Requests\ListIncidentLessonsRequest;
use App\Http\Requests\ListIncidentNotificationEventsRequest;
use App\Http\Requests\ListIncidentNotificationsRequest;
use App\Http\Requests\ListIncidentsRequest;
use App\Http\Requests\ListIncidentTaskEventsRequest;
use App\Http\Requests\RecordIncidentLessonProgressRequest;
use App\Http\Requests\RecordIncidentNotificationDecisionRequest;
use App\Http\Requests\RecordIncidentTaskEventRequest;
use App\Http\Requests\ShowIncidentRequest;
use App\Http\Requests\StoreIncidentAffectedEntityRequest;
use App\Http\Requests\StoreIncidentLessonRequest;
use App\Http\Requests\StoreIncidentNotificationRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\TransitionIncidentPhaseRequest;
use App\Incidents\IncidentAffectedEntityManager;
use App\Incidents\IncidentDesk;
use App\Incidents\IncidentLessonManager;
use App\Incidents\IncidentNotificationManager;
use App\Models\Incident;
use App\Models\IncidentLesson;
use App\Models\IncidentLessonEvent;
use App\Models\IncidentNotification;
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
            'phaseTransitions.evidence.linkedBy:id,name',
            'phaseTransitions.evidence.attachment.audit.members',
            'phaseTransitions.evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]);
        $incident->phaseTransitions->each(fn ($transition) => $this->filterPhaseEvidence($transition, $request->user()));
        $incident->loadCount('notifications');
        $incident->loadCount('lessons');

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
            $request->validated('evidence_attachment_ids', []),
        );

        $updated->load(['phaseTransitions.actor:id,name', 'phaseTransitions.evidence.linkedBy:id,name',
            'phaseTransitions.evidence.attachment.audit.members',
            'phaseTransitions.evidence.attachment.dataRequestResponse.dataRequest.audit.members']);
        $updated->phaseTransitions->each(fn ($transition) => $this->filterPhaseEvidence($transition, $request->user()));

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

    public function notifications(ListIncidentNotificationsRequest $request, Incident $incident): JsonResponse
    {
        return response()->json($incident->notifications()->withCount('events')->latest('id')
            ->paginate($request->integer('per_page', 50)));
    }

    public function storeNotification(StoreIncidentNotificationRequest $request, Incident $incident, IncidentNotificationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->register($request->user(), $incident, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function notificationDecision(RecordIncidentNotificationDecisionRequest $request, IncidentNotification $notification, IncidentNotificationManager $manager): JsonResponse
    {
        $event = $manager->recordDecision($request->user(), $notification, $request->validated());

        return response()->json(['data' => $event->load('actor:id,name'), 'notification' => $notification->refresh()]);
    }

    public function notificationEvents(ListIncidentNotificationEventsRequest $request, IncidentNotification $notification): JsonResponse
    {
        return response()->json($notification->events()->with('actor:id,name')
            ->paginate($request->integer('per_page', 50)));
    }

    public function lessons(ListIncidentLessonsRequest $request, Incident $incident): JsonResponse
    {
        return response()->json($incident->lessons()->with('owner:id,name,email')->withCount('events')->latest('id')
            ->paginate($request->integer('per_page', 50)));
    }

    public function affectedEntities(ListIncidentAffectedEntitiesRequest $request, Incident $incident): JsonResponse
    {
        return response()->json($incident->affectedEntities()->with('linkedBy:id,name')
            ->paginate($request->integer('per_page', 50)));
    }

    public function storeAffectedEntity(StoreIncidentAffectedEntityRequest $request, Incident $incident, IncidentAffectedEntityManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->link($request->user(), $incident, $request->validated())->load('linkedBy:id,name')], JsonResponse::HTTP_CREATED);
    }

    public function storeLesson(StoreIncidentLessonRequest $request, Incident $incident, IncidentLessonManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->register($request->user(), $incident, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function lessonProgress(RecordIncidentLessonProgressRequest $request, IncidentLesson $lesson, IncidentLessonManager $manager): JsonResponse
    {
        $event = $manager->recordProgress($request->user(), $lesson, $request->validated());

        return response()->json(['data' => $this->visibleLessonEvent($event, $request->user()), 'lesson' => $lesson->refresh()->load('owner:id,name,email')]);
    }

    public function lessonEvents(ListIncidentLessonEventsRequest $request, IncidentLesson $lesson): JsonResponse
    {
        $events = $lesson->events()->with(['actor:id,name', 'lesson.incident'])
            ->paginate($request->integer('per_page', 50));
        $events->getCollection()->transform(fn (IncidentLessonEvent $event) => $this->visibleLessonEvent($event, $request->user()));

        return response()->json($events);
    }

    private function visibleLessonEvent(IncidentLessonEvent $event, User $actor): IncidentLessonEvent
    {
        $event->loadMissing(['actor:id,name', 'lesson.incident']);
        $visible = clone $event;
        $canViewIncident = $event->lesson?->incident && $actor->can('view', $event->lesson->incident);
        $visible->unsetRelation('lesson');
        if ($canViewIncident) {
            return $visible;
        }

        $before = $visible->before_snapshot;
        $after = $visible->after_snapshot;
        if (is_array($before)) {
            unset($before['incident']);
        }
        if (is_array($after)) {
            unset($after['incident']);
        }
        $visible->setAttribute('before_snapshot', $before);
        $visible->setAttribute('after_snapshot', $after);

        return $visible;
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

    private function filterPhaseEvidence($transition, User $actor): void
    {
        $transition->setRelation('evidence', $transition->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values());
    }
}
