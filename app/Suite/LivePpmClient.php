<?php

namespace App\Suite;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LivePpmClient implements PpmClient
{
    public function createProject(array $payload): array
    {
        return $this->request('POST', '/api/v1/projects', $payload);
    }

    public function getProject(string $projectId): array
    {
        return $this->request('GET', '/api/v1/projects/'.$projectId);
    }

    public function createEntityLink(array $payload): array
    {
        return $this->request('POST', '/api/v1/entity-links', $payload);
    }

    public function createWorkPackage(array $payload): array
    {
        return $this->request('POST', '/api/v1/work-packages', $payload);
    }

    public function listWorkPackageTypes(): array
    {
        $body = $this->request('GET', '/api/v1/work-package-types');

        return $body['items'] ?? [];
    }

    public function postSuiteEvent(string $rawBody, array $headers): void
    {
        $response = Http::withHeaders(array_merge($headers, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]))->withBody($rawBody, 'application/json')
            ->timeout(15)
            ->post(rtrim((string) config('suite.ppm.base_url'), '/').'/api/v1/suite/events');

        if ($response->failed()) {
            throw new RuntimeException('PPM suite inbound rejected the event ('.$response->status().').');
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $pending = Http::withToken((string) config('suite.ppm.token'))
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Tenant-Id' => (string) config('suite.ppm.tenant_id'),
            ])
            ->timeout(15);

        $url = rtrim((string) config('suite.ppm.base_url'), '/').$path;
        $response = $payload === null
            ? $pending->send($method, $url)
            : $pending->send($method, $url, ['json' => $payload]);

        if ($response->failed()) {
            throw new RuntimeException('PPM API '.$method.' '.$path.' failed ('.$response->status().').');
        }

        return $response->json() ?? [];
    }
}
