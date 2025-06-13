<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Exceptions\RateLimitException;

/**
 * API リクエストの実行を担当するクラス
 */
final class RequestProcessor
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private CostCalculator $costCalculator,
        private RateLimiter $rateLimiter,
    ) {
    }

    /**
     * プロバイダーにリクエストを送信し、レスポンスを処理する
     */
    public function process(
        ResolvedRequestConfig $config,
        float $startTime
    ): GenAIResponseData {
        $providerConfig = config("genai.providers.{$config->provider}");

        if (! $providerConfig) {
            throw new \InvalidArgumentException("Provider '{$config->provider}' configuration not found");
        }

        // レート制限チェック
        $this->checkRateLimit($config->provider, $config->model, $config->prompt);

        $providerInstance = $this->providerFactory->create($config->provider, $providerConfig);

        // リトライロジック実装
        $retryConfig = config('genai.retry', []);
        $maxAttempts = $retryConfig['max_attempts'] ?? 3;
        $delay = $retryConfig['delay'] ?? 1000;
        $multiplier = $retryConfig['multiplier'] ?? 2;
        $retryExceptions = $retryConfig['exceptions'] ?? [];

        $attempt = 1;
        $currentDelay = $delay;

        while ($attempt <= $maxAttempts) {
            try {
                $raw = $providerInstance->request(
                    userPrompt: $config->prompt,
                    systemPrompt: $config->systemPrompt,
                    options: $config->options,
                    model: $config->model
                );

                // レスポンスの安全性チェック
                if (! is_array($raw) || ! isset($raw['choices'][0]['message']['content'])) {
                    throw new \RuntimeException('Invalid provider response format');
                }

                // コスト計算
                $cost = $this->costCalculator->calculateCost(
                    model: $config->model,
                    inputTokens: $raw['usage']['input_tokens'] ?? $raw['usage']['prompt_tokens'] ?? 0,
                    outputTokens: $raw['usage']['output_tokens'] ?? $raw['usage']['completion_tokens'] ?? 0,
                    cachedTokens: $raw['usage']['input_tokens_details']['cached_tokens'] ?? $raw['usage']['prompt_tokens_details']['cached_tokens'] ?? 0,
                    reasoningTokens: $raw['usage']['output_tokens_details']['reasoning_tokens'] ?? $raw['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0
                );

                // レート制限カウンターを更新
                $totalTokens = ($raw['usage']['input_tokens'] ?? $raw['usage']['prompt_tokens'] ?? 0) +
                    ($raw['usage']['output_tokens'] ?? $raw['usage']['completion_tokens'] ?? 0);
                $this->rateLimiter->record($config->provider, $config->model, $totalTokens);

                return new GenAIResponseData(
                    content: $raw['choices'][0]['message']['content'] ?? '',
                    usage: $raw['usage'] ?? [],
                    cost: $cost,
                    meta: $raw,
                    cached: false,
                    responseTimeMs: (int) ((microtime(true) - $startTime) * 1000)
                );
            } catch (\Exception $e) {
                $shouldRetry = $this->shouldRetry($e, $retryExceptions, $attempt, $maxAttempts);

                if (! $shouldRetry) {
                    throw $e;
                }

                if ($attempt < $maxAttempts) {
                    usleep($currentDelay * 1000); // ミリ秒をマイクロ秒に変換
                    $currentDelay *= $multiplier;
                }

                $attempt++;
            }
        }

        throw new \RuntimeException('Maximum retry attempts exceeded');
    }

    /**
     * レート制限をチェック
     */
    private function checkRateLimit(string $provider, string $model, string $prompt): void
    {
        // プロンプトの長さからトークン数を推定（簡易計算）
        $estimatedTokens = (int) (strlen($prompt) / 4);

        $result = $this->rateLimiter->check($provider, $model, $estimatedTokens);

        if (! $result['allowed']) {
            throw new RateLimitException(
                'Rate limit exceeded. '.
                    'Requests remaining: '.($result['requests_remaining'] ?? 'N/A').', '.
                    'Tokens remaining: '.($result['tokens_remaining'] ?? 'N/A')
            );
        }
    }

    /**
     * リトライすべきかを判定
     */
    private function shouldRetry(\Exception $e, array $retryExceptions, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        $exceptionClass = get_class($e);
        $exceptionName = class_basename($exceptionClass);

        return in_array($exceptionName, $retryExceptions);
    }

    /**
     * エラーレスポンスを作成
     */
    public function createErrorResponse(
        string $error,
        float $startTime
    ): GenAIResponseData {
        return new GenAIResponseData(
            content: '',
            usage: [],
            cost: 0.0,
            meta: [],
            error: $error,
            cached: false,
            responseTimeMs: (int) ((microtime(true) - $startTime) * 1000)
        );
    }

    /**
     * レスポンスをキャッシュ用データに変換
     */
    public function toCacheData(GenAIResponseData $response): array
    {
        return [
            'content' => $response->content,
            'usage' => $response->usage,
            'cost' => $response->cost,
            'meta' => $response->meta,
        ];
    }
}
