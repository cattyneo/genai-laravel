<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Http;

final class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.openai.com/v1'
    ) {}

    public function request(
        string $userPrompt,
        ?string $systemPrompt = null,
        array $options = [],
        string $model = 'gpt-4o'
    ): array {
        $messages = [];

        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $this->transformOptions($options));

        $timeout = config('genai.defaults.timeout', 30);

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl.'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI API request failed: '.$response->body());
        }

        return $response->json();
    }

    public function transformOptions(array $options): array
    {
        $transformed = array_filter([
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'top_p' => $options['top_p'] ?? null,
            'frequency_penalty' => $options['frequency_penalty'] ?? null,
            'presence_penalty' => $options['presence_penalty'] ?? null,
        ], fn ($value) => $value !== null);

        return $transformed;
    }
}
