<?php

namespace App\Services\Ai;

use App\Ai\AiClient;
use App\Ai\HttpAiClient;
use App\Enums\AiProvider;
use App\Enums\QuotaType;
use App\Services\QuotaService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class AiService
{
    protected AiClient $client;

    protected AiProvider $provider;

    protected string $model;

    protected string $apiKey;

    public function __construct(?AiClient $client = null)
    {
        $this->initializeProvider();
        $this->client = $client ?? $this->defaultClient();
    }

    protected function initializeProvider(): void
    {
        $this->provider = $this->resolveProvider();
        $this->model = $this->resolveModel();
        $this->apiKey = $this->resolveApiKey();
    }

    protected function resolveProvider(): AiProvider
    {
        $settingProvider = setting('ai.provider');
        if ($settingProvider) {
            return AiProvider::tryFrom($settingProvider) ?? AiProvider::OpenAI;
        }

        $configProvider = config('ai.provider');
        if ($configProvider) {
            return AiProvider::tryFrom($configProvider) ?? AiProvider::OpenAI;
        }

        return AiProvider::OpenAI;
    }

    protected function resolveModel(): string
    {
        $settingModel = setting('ai.model');
        if ($settingModel) {
            return $settingModel;
        }

        $configModel = config('ai.model');
        if ($configModel) {
            return $configModel;
        }

        return $this->provider->getDefaultModel();
    }

    protected function resolveApiKey(): string
    {
        $settingKey = setting($this->provider->getSettingKeyName());
        if (filled($settingKey)) {
            try {
                return Crypt::decryptString($settingKey);
            } catch (\Exception $e) {
                // Fall through to env key
            }
        }

        $envKey = config('ai.keys.'.$this->provider->value);
        if (filled($envKey)) {
            return $envKey;
        }

        return '';
    }

    public function getProvider(): AiProvider
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) || $this->client instanceof \App\Ai\StubAiClient;
    }

    /**
     * Send a chat completion request.
     *
     * @return array{content: string, model: string, usage: array}
     *
     * @throws RuntimeException
     * @throws GuzzleException
     */
    public function chatCompletion(string $systemPrompt, string $userPrompt): array
    {
        return $this->client->complete($systemPrompt, $userPrompt, false);
    }

    /**
     * @param  list<string>  $requiredKeys
     * @return array{content: array<string, mixed>, model: string, usage: array<string, mixed>}
     */
    public function chatJson(string $systemPrompt, string $userPrompt, array $requiredKeys = []): array
    {
        $this->assertQuota();

        $raw = $this->client->complete($systemPrompt, $userPrompt, true);
        $decoded = json_decode($raw['content'], true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI returned invalid JSON');
        }

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $decoded)) {
                throw new RuntimeException('AI returned invalid JSON');
            }
        }

        $this->recordUsage($raw['usage'] ?? []);

        return [
            'content' => $decoded,
            'model' => $raw['model'] ?? $this->model,
            'usage' => $raw['usage'] ?? [],
        ];
    }

    private function assertQuota(): void
    {
        QuotaService::check(QuotaType::AI_PROMPT, 1);
        QuotaService::check(QuotaType::AI_RESPONSE, 1);
    }

    /** @param array<string, mixed> $usage */
    private function recordUsage(array $usage): void
    {
        QuotaService::record(QuotaType::AI_PROMPT, max(1, (int) ($usage['prompt_tokens'] ?? 1)));
        QuotaService::record(QuotaType::AI_RESPONSE, max(1, (int) ($usage['completion_tokens'] ?? 1)));
    }

    private function defaultClient(): AiClient
    {
        if (app()->bound(\App\Ai\StubAiClient::class)) {
            return app(\App\Ai\StubAiClient::class);
        }

        return new HttpAiClient(new Client, $this->provider, $this->model, $this->apiKey);
    }
}
