<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

final class ProviderFactory
{
    public function __construct(
        private array $providers
    ) {}

    public function create(string $provider, ?array $config = null): ProviderInterface
    {
        $config = $config ?? $this->providers[$provider] ?? [];

        return $this->createProvider($provider, $config);
    }

    public static function createProvider(string $provider, array $config): ProviderInterface
    {
        return match ($provider) {
            'openai' => new OpenAIProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? 'https://api.openai.com/v1'
            ),
            'gemini' => new GeminiProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'
            ),
            'claude' => new ClaudeProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? 'https://api.anthropic.com/v1'
            ),
            'grok' => new GrokProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? 'https://api.x.ai/v1'
            ),
            default => throw new \InvalidArgumentException("Unsupported provider: {$provider}")
        };
    }

    public static function getAvailableProviders(): array
    {
        return ['openai', 'gemini', 'claude', 'grok'];
    }
}
