<?php

namespace App\Ai;

use App\Enums\AiProvider;
use GuzzleHttp\Client;
use RuntimeException;

class HttpAiClient implements AiClient
{
    public function __construct(
        private readonly Client $http,
        private readonly AiProvider $provider,
        private readonly string $model,
        private readonly string $apiKey,
    ) {}

    public function complete(string $systemPrompt, string $userPrompt, bool $json = false): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('AI provider is not configured. Please set an API key.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if ($this->provider === AiProvider::OpenAI) {
            $payload['store'] = true;
            if ($json) {
                $payload['response_format'] = ['type' => 'json_object'];
            }
        }

        $response = $this->http->request('POST', $this->provider->getEndpoint(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$this->apiKey}",
            ],
            'json' => $payload,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return [
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'model' => $data['model'] ?? $this->model,
            'usage' => $data['usage'] ?? [],
        ];
    }
}
