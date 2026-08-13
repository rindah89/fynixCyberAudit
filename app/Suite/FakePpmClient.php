<?php

namespace App\Suite;

class FakePpmClient implements PpmClient
{
    /** @var list<array<string, mixed>> */
    public array $projects = [];

    /** @var list<array<string, mixed>> */
    public array $links = [];

    /** @var list<array<string, mixed>> */
    public array $workPackages = [];

    /** @var list<array{body: string, headers: array<string, string>}> */
    public array $outboundEvents = [];

    public int $createProjectCalls = 0;

    public int $workPackageSeq = 0;

    public function createProject(array $payload): array
    {
        $this->createProjectCalls++;
        $project = [
            'id' => '33333333-3333-3333-3333-333333333333',
            'name' => (string) ($payload['name'] ?? 'untitled'),
            'status' => 'active',
            'custom_fields' => $payload['custom_fields'] ?? [],
        ];
        $this->projects[] = $project;

        return $project;
    }

    public function getProject(string $projectId): array
    {
        foreach ($this->projects as $project) {
            if ($project['id'] === $projectId) {
                return $project;
            }
        }

        return ['id' => $projectId, 'name' => 'unknown', 'status' => 'active'];
    }

    public function createEntityLink(array $payload): array
    {
        $link = array_merge(['id' => '44444444-4444-4444-4444-444444444444'], $payload);
        $this->links[] = $link;

        return $link;
    }

    public function createWorkPackage(array $payload): array
    {
        $this->workPackageSeq++;
        $wp = [
            'id' => sprintf('55555555-5555-5555-5555-%012d', $this->workPackageSeq),
            'title' => (string) ($payload['title'] ?? ''),
            'state' => $payload['state'] ?? 'new',
            'type_name' => 'Task',
            'type_id' => $payload['type_id'] ?? '66666666-6666-6666-6666-666666666666',
        ];
        $this->workPackages[] = $wp;

        return $wp;
    }

    public function listWorkPackageTypes(): array
    {
        return [
            ['id' => '66666666-6666-6666-6666-666666666666', 'name' => 'Task'],
        ];
    }

    public function postSuiteEvent(string $rawBody, array $headers): void
    {
        $this->outboundEvents[] = ['body' => $rawBody, 'headers' => $headers];
    }

    public function setProjectStatus(string $projectId, string $status): void
    {
        foreach ($this->projects as $i => $project) {
            if ($project['id'] === $projectId) {
                $this->projects[$i]['status'] = $status;
            }
        }
    }
}
