<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Models\GenAIRequest;

/**
 * ログ記録の統一インターフェースを提供するアダプター
 */
final class LoggerAdapter
{
    public function __construct(
        private DatabaseLogger|RequestLogger $logger
    ) {
    }

    /**
     * リクエストをログに記録
     */
    public function logRequest(
        GenAIRequestData $request,
        GenAIResponseData $response,
        string $provider,
        string $model,
        float $durationMs,
        string $error = null
    ): ?GenAIRequest {
        return $this->logger->logRequest(
            $request,
            $response,
            $provider,
            $model,
            $durationMs,
            $error
        );
    }

    /**
     * 保留中の統計を処理（DatabaseLoggerのみ対応）
     */
    public function flushPendingStats(): void
    {
        if ($this->logger instanceof DatabaseLogger) {
            $this->logger->flushPendingStats();
        }
        // RequestLoggerの場合は何もしない
    }

    /**
     * 使用中のロガーの種類を確認
     */
    public function isOptimizedLogger(): bool
    {
        return $this->logger instanceof DatabaseLogger;
    }

    /**
     * 使用中のロガーインスタンスを取得
     */
    public function getLogger(): DatabaseLogger|RequestLogger
    {
        return $this->logger;
    }
}
