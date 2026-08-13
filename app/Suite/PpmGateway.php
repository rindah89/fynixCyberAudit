<?php

namespace App\Suite;

use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\SuiteEntityLink;
use App\Models\User;
use App\Support\Enterprise;

class PpmGateway
{
    public function __construct(private readonly PpmClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('suite.ppm.enabled')
            && filled(config('suite.ppm.base_url'))
            && filled(config('suite.ppm.token'))
            && filled(config('suite.ppm.tenant_id'));
    }

    public function publishProject(User $actor, RemediationProject $project): SuiteEntityLink
    {
        if (! Enterprise::enabled('remediation') || ! $this->enabled()) {
            abort(404, 'PPM suite integration is not enabled.');
        }

        if (! $project->isMember($actor)) {
            abort(403, 'You cannot publish this remediation to PPM.');
        }

        $existing = SuiteEntityLink::query()
            ->where('local_type', RemediationProject::class)
            ->where('local_id', $project->id)
            ->where('system', 'ppm')
            ->where('entity_type', 'project')
            ->first();

        if ($existing) {
            $this->publishUnpublishedTasks($actor, $project, $existing);

            return $existing;
        }

        $created = $this->client->createProject([
            'name' => '['.$project->code.'] '.$project->name,
            'description' => $this->projectDescription($project),
            'custom_fields' => [
                'grc_code' => $project->code,
                'grc_entity_type' => 'remediation_project',
                'grc_entity_id' => (string) $project->id,
                'grc_url' => $this->grcUrl($project),
            ],
        ]);

        $this->client->createEntityLink([
            'local_type' => 'project',
            'local_id' => $created['id'],
            'system' => 'grc',
            'entity_type' => 'remediation_project',
            'entity_id' => (string) $project->id,
            'relation' => 'derived_from',
        ]);

        $this->emitRemediationPublished($project, $created['id']);

        $link = SuiteEntityLink::query()->create([
            'local_type' => RemediationProject::class,
            'local_id' => $project->id,
            'system' => 'ppm',
            'entity_type' => 'project',
            'entity_id' => $created['id'],
            'relation' => 'derived_from',
            'remote_status' => $created['status'] ?? 'active',
            'remote_url' => $this->ppmBoardUrl($created['id']),
            'meta' => ['name' => $created['name'] ?? $project->name],
        ]);

        $this->publishUnpublishedTasks($actor, $project, $link);

        return $link;
    }

    public function publishTask(User $actor, RemediationTask $task): ?SuiteEntityLink
    {
        if (! Enterprise::enabled('remediation') || ! $this->enabled()) {
            return null;
        }

        $existing = SuiteEntityLink::query()
            ->where('local_type', RemediationTask::class)
            ->where('local_id', $task->id)
            ->where('system', 'ppm')
            ->where('entity_type', 'work_package')
            ->first();

        if ($existing) {
            return $existing;
        }

        $typeId = $this->resolveWorkTypeId();
        if ($typeId === null) {
            return null;
        }

        $projectLink = SuiteEntityLink::query()
            ->where('local_type', RemediationProject::class)
            ->where('local_id', $task->remediation_project_id)
            ->where('system', 'ppm')
            ->where('entity_type', 'project')
            ->first();

        if (! $projectLink) {
            $projectLink = $this->publishProject($actor, $task->project);
            $again = SuiteEntityLink::query()
                ->where('local_type', RemediationTask::class)
                ->where('local_id', $task->id)
                ->where('entity_type', 'work_package')
                ->first();
            if ($again) {
                return $again;
            }
        }

        $created = $this->client->createWorkPackage([
            'project_id' => $projectLink->entity_id,
            'type_id' => $typeId,
            'title' => $task->number.' '.$task->title,
            'description' => $task->weakness_description,
            'custom_fields' => [
                'grc_entity_type' => 'remediation_task',
                'grc_entity_id' => (string) $task->id,
            ],
        ]);

        $this->client->createEntityLink([
            'local_type' => 'work_package',
            'local_id' => $created['id'],
            'system' => 'grc',
            'entity_type' => 'remediation_task',
            'entity_id' => (string) $task->id,
            'relation' => 'derived_from',
        ]);

        $this->emitTaskPublished($task, $projectLink->entity_id, $created['id']);

        return SuiteEntityLink::query()->create([
            'local_type' => RemediationTask::class,
            'local_id' => $task->id,
            'system' => 'ppm',
            'entity_type' => 'work_package',
            'entity_id' => $created['id'],
            'relation' => 'derived_from',
            'remote_status' => $created['state'] ?? 'new',
            'remote_url' => $this->ppmBoardUrl($projectLink->entity_id),
            'meta' => [
                'title' => $created['title'] ?? $task->title,
                'type_name' => $created['type_name'] ?? 'Task',
            ],
        ]);
    }

    private function publishUnpublishedTasks(User $actor, RemediationProject $project, SuiteEntityLink $projectLink): void
    {
        foreach ($project->tasks as $task) {
            $this->publishTask($actor, $task);
        }
    }

    private function resolveWorkTypeId(): ?string
    {
        $configured = config('suite.ppm.default_work_type_id');
        if (filled($configured)) {
            return (string) $configured;
        }

        foreach ($this->client->listWorkPackageTypes() as $type) {
            $name = strtolower((string) ($type['name'] ?? ''));
            if ($name === 'task' || str_contains($name, 'task')) {
                return (string) $type['id'];
            }
        }

        $first = $this->client->listWorkPackageTypes()[0] ?? null;

        return isset($first['id']) ? (string) $first['id'] : null;
    }

    /** @param array<string, mixed> $envelope */
    public function applyPpmEvent(array $envelope): string
    {
        $eventType = (string) ($envelope['event_type'] ?? '');
        $entityType = (string) ($envelope['entity_type'] ?? '');
        $entityId = (string) ($envelope['entity_id'] ?? '');

        if (! in_array($eventType, ['project.updated', 'project.deleted', 'work_package.updated', 'work_package.deleted'], true)) {
            return 'ignored';
        }

        $link = SuiteEntityLink::query()
            ->where('system', 'ppm')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if (! $link) {
            return 'ignored';
        }

        if (str_ends_with($eventType, '.deleted')) {
            $link->update(['remote_status' => 'deleted']);

            return 'applied';
        }

        $status = $envelope['payload']['status'] ?? $envelope['payload']['state'] ?? null;
        if ($status === null && $entityType === 'project') {
            $remote = $this->client->getProject($entityId);
            $status = $remote['status'] ?? null;
        }

        $link->update([
            'remote_status' => $status,
            'meta' => array_merge($link->meta ?? [], [
                'name' => $envelope['payload']['name'] ?? ($link->meta['name'] ?? null),
            ]),
        ]);

        return 'applied';
    }

    private function projectDescription(RemediationProject $project): string
    {
        return implode("\n", array_filter([
            'Fynix Cyber Audit POA&M '.$project->code,
            $this->grcUrl($project),
        ]));
    }

    private function grcUrl(RemediationProject $project): string
    {
        return rtrim((string) config('app.url'), '/').'/app/remediation-projects/'.$project->id;
    }

    private function ppmBoardUrl(string $ppmProjectId): string
    {
        return rtrim((string) config('suite.ppm.public_url'), '/').'/projects/'.$ppmProjectId.'/board';
    }

    private function emitRemediationPublished(RemediationProject $project, string $ppmProjectId): void
    {
        $secrets = config('suite.ppm.webhook_secrets', []);
        $webhookId = (string) config('suite.ppm.webhook_id');
        if ($secrets === [] || $webhookId === '') {
            return;
        }

        $envelope = [
            'event_type' => 'grc.remediation.published',
            'tenant_id' => (string) config('suite.ppm.tenant_id'),
            'entity_type' => 'remediation_project',
            'entity_id' => (string) $project->id,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => [
                'ppm_project_id' => $ppmProjectId,
                'grc_entity_type' => 'remediation_project',
                'grc_entity_id' => (string) $project->id,
                'code' => $project->code,
                'grc_url' => $this->grcUrl($project),
            ],
        ];
        $raw = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId = (string) \Illuminate\Support\Str::uuid();
        $headers = [
            'X-Fynix-Timestamp' => (string) $timestamp,
            'X-Fynix-Event' => 'grc.remediation.published',
            'X-Fynix-Source' => 'grc',
            'X-Fynix-Webhook-Id' => $webhookId,
            'X-Fynix-Delivery-Id' => $deliveryId,
            'X-Fynix-Signature' => SuiteEnvelope::sign(
                (string) $secrets[0],
                $timestamp,
                'grc.remediation.published',
                'grc',
                $webhookId,
                $deliveryId,
                (string) $raw,
            ),
        ];

        $this->client->postSuiteEvent((string) $raw, $headers);
    }

    private function emitTaskPublished(RemediationTask $task, string $ppmProjectId, string $workPackageId): void
    {
        $secrets = config('suite.ppm.webhook_secrets', []);
        $webhookId = (string) config('suite.ppm.webhook_id');
        if ($secrets === [] || $webhookId === '') {
            return;
        }

        $envelope = [
            'event_type' => 'grc.remediation.task_published',
            'tenant_id' => (string) config('suite.ppm.tenant_id'),
            'entity_type' => 'remediation_task',
            'entity_id' => (string) $task->id,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => [
                'ppm_project_id' => $ppmProjectId,
                'ppm_work_package_id' => $workPackageId,
                'grc_entity_type' => 'remediation_task',
                'grc_entity_id' => (string) $task->id,
                'number' => $task->number,
            ],
        ];
        $raw = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId = (string) \Illuminate\Support\Str::uuid();
        $this->client->postSuiteEvent((string) $raw, [
            'X-Fynix-Timestamp' => (string) $timestamp,
            'X-Fynix-Event' => 'grc.remediation.task_published',
            'X-Fynix-Source' => 'grc',
            'X-Fynix-Webhook-Id' => $webhookId,
            'X-Fynix-Delivery-Id' => $deliveryId,
            'X-Fynix-Signature' => SuiteEnvelope::sign(
                (string) $secrets[0],
                $timestamp,
                'grc.remediation.task_published',
                'grc',
                $webhookId,
                $deliveryId,
                (string) $raw,
            ),
        ]);
    }
}
