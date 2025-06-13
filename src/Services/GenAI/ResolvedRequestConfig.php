<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

/**
 * 解決されたリクエスト設定
 */
readonly class ResolvedRequestConfig
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $prompt,
        public ?string $systemPrompt,
        public array $options,
        public array $vars,
        public bool $stream,
    ) {}
}
