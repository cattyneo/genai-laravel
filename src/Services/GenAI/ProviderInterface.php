<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

interface ProviderInterface
{
    /**
     * GenAI APIへのリクエストを実行する
     */
    public function request(
        string $userPrompt,
        ?string $systemPrompt = null,
        array $options = [],
        string $model = 'gpt-4o'
    ): array;

    /**
     * オプションを各プロバイダー用に変換する
     */
    public function transformOptions(array $options): array;
}
