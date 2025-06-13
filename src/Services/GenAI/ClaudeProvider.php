<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Http;

final class ClaudeProvider implements ProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.anthropic.com/v1'
    ) {}

    public function request(
        string $userPrompt,
        ?string $systemPrompt = null,
        array $options = [],
        string $model = 'claude-3-5-sonnet-20241022'
    ): array {
        $messages = [
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ];

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ], $this->transformOptions($options));

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $timeout = config('genai.defaults.timeout', 30);

        $response = Http::timeout($timeout)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ])->post($this->baseUrl.'/messages', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Claude API request failed: '.$response->body());
        }

        $data = $response->json();

        // OpenAI形式に変換
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $data['content'][0]['text'] ?? '',
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
                // 後方互換性のため
                'prompt_tokens' => $data['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
            ],
            'raw' => $data,
        ];
    }

    public function transformOptions(array $options): array
    {
        return array_filter([
            'temperature' => $options['temperature'] ?? null,
            'top_p' => $options['top_p'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
