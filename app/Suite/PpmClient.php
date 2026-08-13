<?php

namespace App\Suite;

interface PpmClient
{
    /** @return array{id: string, name: string, status?: string} */
    public function createProject(array $payload): array;

    /** @return array{id: string, name: string, status?: string} */
    public function getProject(string $projectId): array;

    /** @return array{id: string} */
    public function createEntityLink(array $payload): array;

    /** @return array{id: string, title: string, state?: string, type_name?: string} */
    public function createWorkPackage(array $payload): array;

    /** @return list<array{id: string, name: string}> */
    public function listWorkPackageTypes(): array;

    public function postSuiteEvent(string $rawBody, array $headers): void;
}
