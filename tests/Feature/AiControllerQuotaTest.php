<?php

namespace Tests\Feature;

use App\Ai\StubAiClient;
use App\Enums\QuotaType;
use App\Http\Controllers\AiController;
use App\Models\Control;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiControllerQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_implementation_check_returns_quota_html_when_usage_is_at_the_limit(): void
    {
        $client = new StubAiClient;
        $client->queue('<p>should not be used</p>');
        $this->app->instance(StubAiClient::class, $client);

        $control = Control::factory()->create([
            'description' => 'Encrypt backups at rest.',
        ]);

        QuotaService::record(QuotaType::AI_PROMPT, QuotaService::getLimit(QuotaType::AI_PROMPT));

        $html = (string) AiController::getImplementationCheck($control);

        $this->assertStringContainsString('AI Quota Exceeded', $html);
        $this->assertStringNotContainsString('should not be used', $html);
        $this->assertSame(0, $client->calls);
    }

    public function test_implementation_check_records_tokens_once_on_success(): void
    {
        $client = new StubAiClient;
        $client->queue('<div>Meets Requirements</div>');
        $this->app->instance(StubAiClient::class, $client);

        $control = Control::factory()->create([
            'description' => 'Encrypt backups at rest.',
        ]);

        $beforePrompt = QuotaService::getUsage(QuotaType::AI_PROMPT);
        $beforeResponse = QuotaService::getUsage(QuotaType::AI_RESPONSE);

        $html = (string) AiController::getImplementationCheck($control);

        $this->assertStringContainsString('Meets Requirements', $html);
        $this->assertSame(1, $client->calls);
        $this->assertSame($beforePrompt + 8, QuotaService::getUsage(QuotaType::AI_PROMPT));
        $this->assertSame($beforeResponse + 8, QuotaService::getUsage(QuotaType::AI_RESPONSE));
    }
}
