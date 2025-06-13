<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Services\GenAI\ResolvedRequestConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;

/**
 * 非同期API リクエストの実行を担当するクラス
 */
final class AsyncRequestProcessor
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private CostCalculator $costCalculator,
    ) {}

    /**
     * 非同期でプロバイダーにリクエストを送信
     */
    public function processAsync(
        ResolvedRequestConfig $config,
        float $startTime
    ): \Illuminate\Http\Client\Response {
        $providerConfig = config("genai.providers.{$config->provider}");

        if (!$providerConfig) {
            throw new \InvalidArgumentException("Provider '{$config->provider}' configuration not found");
        }

        $timeout = config('genai.defaults.timeout', 30);

        // プロバイダー固有のエンドポイントとペイロードを準備
        $requestData = $this->prepareRequestData($config, $providerConfig);

        // 非同期HTTPリクエスト
        return Http::timeout($timeout)
            ->withHeaders($requestData['headers'])
            ->async()
            ->post($requestData['url'], $requestData['payload']);
    }

    /**
     * 複数のリクエストを並列処理
     */
    public function processMultipleAsync(array $configs, float $startTime): array
    {
        $responses = Http::pool(function (Pool $pool) use ($configs) {
            $requests = [];

            foreach ($configs as $index => $config) {
                $providerConfig = config("genai.providers.{$config->provider}");
                $requestData = $this->prepareRequestData($config, $providerConfig);
                $timeout = config('genai.defaults.timeout', 30);

                $requests[] = $pool->timeout($timeout)
                    ->withHeaders($requestData['headers'])
                    ->post($requestData['url'], $requestData['payload']);
            }

            return $requests;
        });

        $results = [];
        foreach ($responses as $index => $response) {
            $config = $configs[$index];

            if ($response->successful()) {
                $raw = $response->json();
                $results[] = $this->createResponse($raw, $config->model, $startTime);
            } else {
                $results[] = $this->createErrorResponse(
                    "Request failed: " . $response->body(),
                    $startTime
                );
            }
        }

        return $results;
    }

    /**
     * プロバイダー固有のリクエストデータを準備
     */
    public function prepareRequestData(ResolvedRequestConfig $config, array $providerConfig): array
    {
        $providerInstance = $this->providerFactory->create($config->provider, $providerConfig);

        switch ($config->provider) {
            case 'openai':
            case 'grok':
                $messages = [];
                if ($config->systemPrompt) {
                    $messages[] = ['role' => 'system', 'content' => $config->systemPrompt];
                }
                $messages[] = ['role' => 'user', 'content' => $config->prompt];

                return [
                    'url' => $providerConfig['base_url'] . '/chat/completions',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $providerConfig['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'payload' => array_merge([
                        'model' => $config->model,
                        'messages' => $messages,
                    ], $providerInstance->transformOptions($config->options))
                ];

            case 'claude':
                $messages = [['role' => 'user', 'content' => $config->prompt]];
                $payload = array_merge([
                    'model' => $config->model,
                    'messages' => $messages,
                    'max_tokens' => $config->options['max_tokens'] ?? 4096,
                ], $providerInstance->transformOptions($config->options));

                if ($config->systemPrompt) {
                    $payload['system'] = $config->systemPrompt;
                }

                return [
                    'url' => $providerConfig['base_url'] . '/messages',
                    'headers' => [
                        'x-api-key' => $providerConfig['api_key'],
                        'Content-Type' => 'application/json',
                        'anthropic-version' => '2023-06-01',
                    ],
                    'payload' => $payload
                ];

            case 'gemini':
                $contents = [];
                if ($config->systemPrompt) {
                    $contents[] = [
                        'parts' => [['text' => $config->systemPrompt]],
                        'role' => 'model'
                    ];
                }
                $contents[] = [
                    'parts' => [['text' => $config->prompt]],
                    'role' => 'user'
                ];

                return [
                    'url' => "{$providerConfig['base_url']}/models/{$config->model}:generateContent?key={$providerConfig['api_key']}",
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'payload' => array_merge([
                        'contents' => $contents,
                    ], $providerInstance->transformOptions($config->options))
                ];

            default:
                throw new \InvalidArgumentException("Unsupported provider for async processing: {$config->provider}");
        }
    }

    /**
     * レスポンスを作成
     */
    public function createResponse(array $raw, string $model, float $startTime): GenAIResponseData
    {
        $cost = $this->costCalculator->calculateCost(
            model: $model,
            inputTokens: $raw['usage']['input_tokens'] ?? $raw['usage']['prompt_tokens'] ?? 0,
            outputTokens: $raw['usage']['output_tokens'] ?? $raw['usage']['completion_tokens'] ?? 0,
            cachedTokens: $raw['usage']['input_tokens_details']['cached_tokens'] ?? $raw['usage']['prompt_tokens_details']['cached_tokens'] ?? 0,
            reasoningTokens: $raw['usage']['output_tokens_details']['reasoning_tokens'] ?? $raw['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0
        );

        return new GenAIResponseData(
            content: $raw['choices'][0]['message']['content'] ??
                $raw['candidates'][0]['content']['parts'][0]['text'] ??
                $raw['content'][0]['text'] ?? '',
            usage: $raw['usage'] ?? $raw['usageMetadata'] ?? [],
            cost: $cost,
            meta: $raw,
            cached: false,
            responseTimeMs: (int)((microtime(true) - $startTime) * 1000)
        );
    }

    /**
     * エラーレスポンスを作成
     */
    private function createErrorResponse(string $error, float $startTime): GenAIResponseData
    {
        return new GenAIResponseData(
            content: '',
            usage: [],
            cost: 0.0,
            meta: [],
            error: $error,
            cached: false,
            responseTimeMs: (int)((microtime(true) - $startTime) * 1000)
        );
    }
}
