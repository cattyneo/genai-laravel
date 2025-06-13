<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

final class MockProvider implements ProviderInterface
{
    public function request(
        string $userPrompt,
        string $systemPrompt = null,
        array $options = [],
        string $model = 'gpt-4o'
    ): array {
        // モック応答を返す
        return [
            'id' => 'chatcmpl-mock-'.uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => "Mock response to: {$userPrompt}".
                            ($systemPrompt ? " (System: {$systemPrompt})" : ''),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'input_tokens' => (int) (strlen($userPrompt) / 4), // 大まかな推定
                'output_tokens' => 20,
                'total_tokens' => (int) (strlen($userPrompt) / 4) + 20,
                // 後方互換性のため
                'prompt_tokens' => (int) (strlen($userPrompt) / 4),
                'completion_tokens' => 20,
            ],
        ];
    }

    public function transformOptions(array $options): array
    {
        return $options;
    }
}
