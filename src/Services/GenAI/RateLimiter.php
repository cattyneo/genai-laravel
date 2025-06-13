<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * GenAI APIのレート制限管理クラス
 */
final class RateLimiter
{
    private const WINDOW_SIZE = 60; // 1分

    public function __construct(
        private array $rateLimits,
        private string $cacheDriver = 'redis'
    ) {
    }

    /**
     * レート制限をチェック
     */
    public function check(
        string $provider,
        string $model,
        int $estimatedTokens = 0,
        string $userId = null
    ): array {
        $userId = $userId ?? $this->getDefaultUserId();

        // プロバイダー、モデル、デフォルトの順で制限設定を取得
        $limits = $this->resolveLimits($provider, $model);

        $now = time();
        $results = [
            'allowed' => true,
            'requests_remaining' => null,
            'tokens_remaining' => null,
            'reset_time' => $now + self::WINDOW_SIZE,
            'limits' => $limits,
            'current_usage' => [],
        ];

        // リクエスト数制限チェック
        if (isset($limits['requests_per_minute'])) {
            $requestResult = $this->checkRequestLimit(
                $provider,
                $model,
                $userId,
                $limits['requests_per_minute'],
                $now
            );

            $results['allowed'] = $results['allowed'] && $requestResult['allowed'];
            $results['requests_remaining'] = $requestResult['remaining'];
            $results['current_usage']['requests'] = $requestResult['current'];
        }

        // トークン数制限チェック
        if (isset($limits['tokens_per_minute']) && $estimatedTokens > 0) {
            $tokenResult = $this->checkTokenLimit(
                $provider,
                $model,
                $userId,
                $estimatedTokens,
                $limits['tokens_per_minute'],
                $now
            );

            $results['allowed'] = $results['allowed'] && $tokenResult['allowed'];
            $results['tokens_remaining'] = $tokenResult['remaining'];
            $results['current_usage']['tokens'] = $tokenResult['current'];
        }

        // 日次制限チェック
        if (isset($limits['requests_per_day'])) {
            $dailyResult = $this->checkDailyLimit(
                $provider,
                $model,
                $userId,
                $limits['requests_per_day'],
                $now
            );

            $results['allowed'] = $results['allowed'] && $dailyResult['allowed'];
            $results['current_usage']['daily_requests'] = $dailyResult['current'];
        }

        if (! $results['allowed']) {
            $this->logRateLimitHit($provider, $model, $userId, $results);
        }

        return $results;
    }

    /**
     * リクエスト実行後のカウンター更新
     */
    public function record(
        string $provider,
        string $model,
        int $actualTokens,
        string $userId = null
    ): void {
        $userId = $userId ?? $this->getDefaultUserId();
        $now = time();

        // リクエストカウンターを増加
        $this->incrementRequestCount($provider, $model, $userId, $now);

        // トークンカウンターを増加
        if ($actualTokens > 0) {
            $this->incrementTokenCount($provider, $model, $userId, $actualTokens, $now);
        }

        // 日次カウンターを増加
        $this->incrementDailyCount($provider, $model, $userId, $now);
    }

    /**
     * レート制限設定を解決
     */
    private function resolveLimits(string $provider, string $model): array
    {
        // モデル固有の制限が最優先
        if (isset($this->rateLimits['models'][$model])) {
            return $this->rateLimits['models'][$model];
        }

        // プロバイダー固有の制限
        if (isset($this->rateLimits['providers'][$provider])) {
            return $this->rateLimits['providers'][$provider];
        }

        // デフォルト制限
        return $this->rateLimits['default'] ?? [];
    }

    /**
     * リクエスト数制限をチェック
     */
    private function checkRequestLimit(
        string $provider,
        string $model,
        string $userId,
        int $limit,
        int $now
    ): array {
        $key = $this->getRequestKey($provider, $model, $userId, $now);
        $current = $this->getCount($key);

        return [
            'allowed' => $current < $limit,
            'remaining' => max(0, $limit - $current),
            'current' => $current,
            'limit' => $limit,
        ];
    }

    /**
     * トークン数制限をチェック
     */
    private function checkTokenLimit(
        string $provider,
        string $model,
        string $userId,
        int $estimatedTokens,
        int $limit,
        int $now
    ): array {
        $key = $this->getTokenKey($provider, $model, $userId, $now);
        $current = $this->getCount($key);

        return [
            'allowed' => ($current + $estimatedTokens) <= $limit,
            'remaining' => max(0, $limit - $current),
            'current' => $current,
            'limit' => $limit,
        ];
    }

    /**
     * 日次制限をチェック
     */
    private function checkDailyLimit(
        string $provider,
        string $model,
        string $userId,
        int $limit,
        int $now
    ): array {
        $key = $this->getDailyKey($provider, $model, $userId, $now);
        $current = $this->getCount($key);

        return [
            'allowed' => $current < $limit,
            'remaining' => max(0, $limit - $current),
            'current' => $current,
            'limit' => $limit,
        ];
    }

    /**
     * リクエストカウンターを増加
     */
    private function incrementRequestCount(
        string $provider,
        string $model,
        string $userId,
        int $now
    ): void {
        $key = $this->getRequestKey($provider, $model, $userId, $now);
        $this->incrementCount($key, 1, self::WINDOW_SIZE);
    }

    /**
     * トークンカウンターを増加
     */
    private function incrementTokenCount(
        string $provider,
        string $model,
        string $userId,
        int $tokens,
        int $now
    ): void {
        $key = $this->getTokenKey($provider, $model, $userId, $now);
        $this->incrementCount($key, $tokens, self::WINDOW_SIZE);
    }

    /**
     * 日次カウンターを増加
     */
    private function incrementDailyCount(
        string $provider,
        string $model,
        string $userId,
        int $now
    ): void {
        $key = $this->getDailyKey($provider, $model, $userId, $now);
        $this->incrementCount($key, 1, 86400); // 24時間
    }

    /**
     * キャッシュからカウンター値を取得
     */
    private function getCount(string $key): int
    {
        try {
            return (int) Cache::store($this->cacheDriver)->get($key, 0);
        } catch (\Exception $e) {
            Log::warning('Rate limiter cache get failed: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * カウンターを増加
     */
    private function incrementCount(string $key, int $increment, int $ttl): void
    {
        try {
            $cache = Cache::store($this->cacheDriver);

            if ($cache->has($key)) {
                $cache->increment($key, $increment);
            } else {
                $cache->put($key, $increment, $ttl);
            }
        } catch (\Exception $e) {
            Log::warning('Rate limiter cache increment failed: '.$e->getMessage());
        }
    }

    /**
     * リクエスト用キーを生成
     */
    private function getRequestKey(string $provider, string $model, string $userId, int $now): string
    {
        $window = floor($now / self::WINDOW_SIZE);

        return "genai:rate_limit:requests:{$provider}:{$model}:{$userId}:{$window}";
    }

    /**
     * トークン用キーを生成
     */
    private function getTokenKey(string $provider, string $model, string $userId, int $now): string
    {
        $window = floor($now / self::WINDOW_SIZE);

        return "genai:rate_limit:tokens:{$provider}:{$model}:{$userId}:{$window}";
    }

    /**
     * 日次用キーを生成
     */
    private function getDailyKey(string $provider, string $model, string $userId, int $now): string
    {
        $day = date('Y-m-d', $now);

        return "genai:rate_limit:daily:{$provider}:{$model}:{$userId}:{$day}";
    }

    /**
     * デフォルトユーザーIDを取得
     */
    private function getDefaultUserId(): string
    {
        return Auth::id() ?? session()->getId() ?? 'anonymous';
    }

    /**
     * レート制限ヒットをログに記録
     */
    private function logRateLimitHit(string $provider, string $model, string $userId, array $results): void
    {
        Log::warning('Rate limit hit', [
            'provider' => $provider,
            'model' => $model,
            'user_id' => $userId,
            'current_usage' => $results['current_usage'],
            'limits' => $results['limits'],
        ]);
    }

    /**
     * レート制限統計を取得
     */
    public function getStats(string $provider, string $model, string $userId = null): array
    {
        $userId = $userId ?? $this->getDefaultUserId();
        $now = time();

        $stats = [
            'provider' => $provider,
            'model' => $model,
            'user_id' => $userId,
            'current_window' => [],
            'daily' => [],
        ];

        // 現在の分間ウィンドウの統計
        $requestKey = $this->getRequestKey($provider, $model, $userId, $now);
        $tokenKey = $this->getTokenKey($provider, $model, $userId, $now);

        $stats['current_window'] = [
            'requests' => $this->getCount($requestKey),
            'tokens' => $this->getCount($tokenKey),
        ];

        // 日次統計
        $dailyKey = $this->getDailyKey($provider, $model, $userId, $now);
        $stats['daily']['requests'] = $this->getCount($dailyKey);

        return $stats;
    }

    /**
     * レート制限をリセット
     */
    public function reset(string $provider, string $model, string $userId = null): void
    {
        $userId = $userId ?? $this->getDefaultUserId();
        $now = time();

        $keys = [
            $this->getRequestKey($provider, $model, $userId, $now),
            $this->getTokenKey($provider, $model, $userId, $now),
            $this->getDailyKey($provider, $model, $userId, $now),
        ];

        try {
            $cache = Cache::store($this->cacheDriver);
            foreach ($keys as $key) {
                $cache->forget($key);
            }
        } catch (\Exception $e) {
            Log::warning('Rate limiter reset failed: '.$e->getMessage());
        }
    }
}
