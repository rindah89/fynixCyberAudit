<?php

namespace App\Ai;

interface AiClient
{
    /**
     * @return array{content: string, model: string, usage: array<string, mixed>}
     */
    public function complete(string $systemPrompt, string $userPrompt, bool $json = false): array;
}
