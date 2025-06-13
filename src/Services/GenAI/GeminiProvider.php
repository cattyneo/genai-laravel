<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Http;

final class GeminiProvider implements ProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta'
    ) {
    }

    public function request(
        string $userPrompt,
        string $systemPrompt = null,
        array $options = [],
        string $model = 'gemini-2.0-flash-exp'
    ): array {
        $contents = [];

        if ($systemPrompt) {
            $contents[] = [
                'parts' => [['text' => $systemPrompt]],
                'role' => 'model',
            ];
        }

        $contents[] = [
            'parts' => [['text' => $userPrompt]],
            'role' => 'user',
        ];

        $payload = array_merge([
            'contents' => $contents,
        ], $this->transformOptions($options));

        $timeout = config('genai.defaults.timeout', 30);

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini API request failed: '.$response->body());
        }

        $data = $response->json();

        // OpenAI形式に変換
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
                // 後方互換性のため
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            ],
            'raw' => $data,
        ];
    }

    public function transformOptions(array $options): array
    {
        $generationConfig = [];

        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = $options['max_tokens'];
        }
        if (isset($options['top_p'])) {
            $generationConfig['topP'] = $options['top_p'];
        }

        return empty($generationConfig) ? [] : ['generationConfig' => $generationConfig];
    }
}
