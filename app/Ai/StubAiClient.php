<?php

namespace App\Ai;

class StubAiClient implements AiClient
{
    public int $calls = 0;

    /** @var list<string> */
    private array $queue = [];

    public function queue(string $content): void
    {
        $this->queue[] = $content;
    }

    public function complete(string $systemPrompt, string $userPrompt, bool $json = false): array
    {
        $this->calls++;

        return [
            'content' => array_shift($this->queue) ?? '{}',
            'model' => 'stub',
            'usage' => [
                'prompt_tokens' => 8,
                'completion_tokens' => 8,
            ],
        ];
    }
}
