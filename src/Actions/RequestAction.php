<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Actions;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Services\GenAI\AsyncRequestProcessor;
use CattyNeo\LaravelGenAI\Services\GenAI\CacheManager;
use CattyNeo\LaravelGenAI\Services\GenAI\LoggerAdapter;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestConfiguration;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestProcessor;
use CattyNeo\LaravelGenAI\Services\GenAI\ResolvedRequestConfig;

final class RequestAction
{
    public function __construct(
        private RequestConfiguration $configuration,
        private RequestProcessor $processor,
        private AsyncRequestProcessor $asyncProcessor,
        private CacheManager $cache,
        private LoggerAdapter $logger,
    ) {
    }

    /**
     * GenAI APIリクエストを実行する
     */
    public function __invoke(GenAIRequestData $request): GenAIResponseData
    {
        $startTime = microtime(true);

        // 設定を解決
        $config = $this->configuration->resolve($request);

        // キャッシュチェック
        $cached = $this->cache->get(
            $config->provider,
            $config->model,
            $config->prompt,
            $config->options
        );

        if ($cached) {
            $this->cache->recordHit();

            return new GenAIResponseData(
                content: $cached['content'],
                usage: $cached['usage'] ?? [],
                cost: $cached['cost'] ?? 0.0,
                meta: $cached['meta'] ?? [],
                cached: true,
                responseTimeMs: 0
            );
        }

        $this->cache->recordMiss();
        $error = null;
        $response = null;

        try {
            // async設定に応じてプロセッサーを選択
            $useAsync = $this->shouldUseAsync($config);

            if ($useAsync) {
                $response = $this->processAsync($config, $startTime);
            } else {
                $response = $this->processor->process($config, $startTime);
            }

            // キャッシュに保存
            $this->cache->put(
                $config->provider,
                $config->model,
                $config->prompt,
                $config->options,
                $this->extractCacheData($response)
            );
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $response = $this->processor->createErrorResponse($error, $startTime);
            throw $e;
        } finally {
            // エラー時でresposeがnullの場合はエラーレスポンスを生成
            if ($response === null) {
                $response = $this->processor->createErrorResponse(
                    $error ?? 'Unknown error',
                    $startTime
                );
            }

            // ログに記録
            $this->logger->logRequest(
                request: $request,
                response: $response,
                provider: $config->provider,
                model: $config->model,
                durationMs: (microtime(true) - $startTime) * 1000,
                error: $error
            );
        }

        return $response;
    }

    /**
     * 非同期処理を使用すべきかを判定
     */
    private function shouldUseAsync($config): bool
    {
        // 設定のasyncフラグをチェック
        $defaultAsync = config('genai.defaults.async', false);
        $requestAsync = $config->options['async'] ?? null;

        // リクエスト固有の設定が優先、次にデフォルト設定
        return $requestAsync ?? $defaultAsync;
    }

    /**
     * 非同期処理を実行
     */
    private function processAsync(ResolvedRequestConfig $config, float $startTime): GenAIResponseData
    {
        // 現在の実装では同期的にHTTPリクエストを実行（将来の拡張用）
        // 将来的には真の非同期処理（Laravel Queues等）を実装予定

        $providerConfig = $this->configuration->getProviderConfig($config->provider);
        $requestData = $this->asyncProcessor->prepareRequestData($config, $providerConfig);

        $timeout = config('genai.defaults.timeout', 30);

        // HTTPリクエスト実行
        $response = \Illuminate\Support\Facades\Http::timeout($timeout)
            ->withHeaders($requestData['headers'])
            ->post($requestData['url'], $requestData['payload']);

        if (! $response->successful()) {
            throw new \RuntimeException('Async API request failed: '.$response->body());
        }

        $raw = $response->json();

        // GenAIResponseDataに変換
        return $this->asyncProcessor->createResponse($raw, $config->model, $startTime);
    }

    /**
     * レスポンスからキャッシュデータを抽出
     */
    private function extractCacheData(GenAIResponseData $response): array
    {
        return [
            'content' => $response->content,
            'usage' => $response->usage,
            'cost' => $response->cost,
            'meta' => $response->meta,
        ];
    }
}
