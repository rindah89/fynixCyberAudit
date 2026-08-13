<?php

namespace Tests\Feature;

use App\Ai\EvidenceSearch;
use App\Ai\StubAiClient;
use App\Enums\QuotaType;
use App\Exceptions\QuotaExceededException;
use App\Models\Policy;
use App\Services\Ai\AiService;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiPrimitivesTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_completion_fails_closed_on_invalid_json(): void
    {
        $client = new StubAiClient;
        $client->queue('this is not json');
        $this->app->instance(StubAiClient::class, $client);

        $service = $this->app->make(AiService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        $service->chatJson('system', 'user', ['verdict']);
    }

    public function test_quota_is_refused_before_the_model_is_called(): void
    {
        $client = new StubAiClient;
        $client->queue('{"verdict":"meets"}');
        $this->app->instance(StubAiClient::class, $client);

        QuotaService::record(QuotaType::AI_PROMPT, QuotaService::getLimit(QuotaType::AI_PROMPT));

        $service = $this->app->make(AiService::class);

        try {
            $service->chatJson('system', 'user', ['verdict']);
            $this->fail('Expected quota to be refused');
        } catch (QuotaExceededException) {
            $this->assertSame(0, $client->calls);
        }
    }

    public function test_evidence_search_returns_a_known_policy_excerpt(): void
    {
        Policy::factory()->create([
            'name' => 'Encryption Standard',
            'body' => 'All customer backups use AES-256-GCM at rest in the quiet vault.',
        ]);

        $hits = $this->app->make(EvidenceSearch::class)->search('AES-256-GCM quiet vault', 5);

        $this->assertNotEmpty($hits);
        $this->assertSame('policy', $hits[0]['type']);
        $this->assertStringContainsString('AES-256-GCM', $hits[0]['excerpt']);
    }
}
