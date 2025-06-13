<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Cache\TaggableStore;

final class CacheManager
{
    public function __construct(
        private array $cacheConfig
    ) {}

    /**
     * キャッシュからレスポンスを取得
     */
    public function get(string $provider, string $model, string $prompt, array $options): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $key = $this->generateCacheKey($provider, $model, $prompt, $options);
        $cache = $this->getCacheStore();

        try {
            return $cache->get($key);
        } catch (\Exception $e) {
            Log::warning("Cache get failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * レスポンスをキャッシュに保存
     */
    public function put(string $provider, string $model, string $prompt, array $options, array $response): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $key = $this->generateCacheKey($provider, $model, $prompt, $options);
        $ttl = $this->cacheConfig['ttl'];
        $cache = $this->getCacheStore();

        try {
            if ($this->supportsTagging()) {
                try {
                    // Redisタグ機能を使用
                    $tags = $this->generateTags($provider, $model);
                    // @phpstan-ignore-next-line
                    $cache->tags($tags)->put($key, $response, $ttl);
                } catch (\Exception $tagException) {
                    // タグ機能が利用できない場合は通常のキャッシュを使用
                    Log::debug("Tag caching failed, using regular cache: " . $tagException->getMessage());
                    $cache->put($key, $response, $ttl);
                }
            } else {
                $cache->put($key, $response, $ttl);
            }

            // メタデータ保存
            $this->storeKeyMetadata($key, $provider, $model);
        } catch (\Exception $e) {
            Log::warning("Cache put failed: " . $e->getMessage());
        }
    }

    /**
     * 特定のキーのキャッシュを削除
     */
    public function forget(string $provider, string $model, string $prompt, array $options): void
    {
        $key = $this->generateCacheKey($provider, $model, $prompt, $options);
        $cache = $this->getCacheStore();

        try {
            $cache->forget($key);
            $this->removeKeyMetadata($key);
        } catch (\Exception $e) {
            Log::warning("Cache forget failed: " . $e->getMessage());
        }
    }

    /**
     * キャッシュをクリア
     */
    public function flush(?string $tag = null): void
    {
        $cache = $this->getCacheStore();

        try {
            if ($tag && $this->supportsTagging()) {
                try {
                    // タグ別クリア
                    // @phpstan-ignore-next-line
                    $cache->tags([$tag])->flush();
                } catch (\Exception $tagException) {
                    Log::debug("Tag flush failed, using full flush: " . $tagException->getMessage());
                    $cache->flush();
                    $this->clearKeyMetadata();
                }
            } else {
                // 全キャッシュクリア
                $cache->flush();
                $this->clearKeyMetadata();
            }
        } catch (\Exception $e) {
            Log::warning("Cache flush failed: " . $e->getMessage());
        }
    }

    /**
     * プロバイダー別にキャッシュをクリア
     */
    public function flushProvider(string $provider): void
    {
        if ($this->supportsTagging()) {
            $tag = $this->generateProviderTag($provider);
            $this->flush($tag);
        } else {
            // タグ機能なしの場合は全キャッシュクリア
            $this->flush();
        }
    }

    /**
     * モデル別にキャッシュをクリア
     */
    public function flushModel(string $model): void
    {
        if ($this->supportsTagging()) {
            $tag = $this->generateModelTag($model);
            $this->flush($tag);
        } else {
            // タグ機能なしの場合は全キャッシュクリア
            $this->flush();
        }
    }

    /**
     * プロバイダー・モデル別にキャッシュをクリア
     */
    public function flushProviderModel(string $provider, string $model): void
    {
        if ($this->supportsTagging()) {
            $tags = $this->generateTags($provider, $model);
            $cache = $this->getCacheStore();

            try {
                // @phpstan-ignore-next-line
                $cache->tags($tags)->flush();
            } catch (\Exception $e) {
                Log::warning("Cache flush by provider-model failed: " . $e->getMessage());
                // フォールバックとして全キャッシュクリア
                $this->flush();
            }
        } else {
            $this->flush();
        }
    }

    /**
     * タグを生成
     */
    private function generateTags(string $provider, string $model): array
    {
        $baseTags = $this->cacheConfig['tags'] ?? ['genai'];

        return array_merge($baseTags, [
            $this->generateProviderTag($provider),
            $this->generateModelTag($model),
            $this->generateProviderModelTag($provider, $model),
        ]);
    }

    /**
     * プロバイダータグを生成
     */
    private function generateProviderTag(string $provider): string
    {
        return "genai:provider:{$provider}";
    }

    /**
     * モデルタグを生成
     */
    private function generateModelTag(string $model): string
    {
        return "genai:model:{$model}";
    }

    /**
     * プロバイダー・モデル組み合わせタグを生成
     */
    private function generateProviderModelTag(string $provider, string $model): string
    {
        return "genai:provider-model:{$provider}:{$model}";
    }

    /**
     * キャッシュキーを生成
     */
    private function generateCacheKey(string $provider, string $model, string $prompt, array $options): string
    {
        // プロンプトとオプションの内容からハッシュを生成
        $contentHash = hash('sha256', serialize([
            'prompt' => $prompt,
            'options' => $this->normalizeOptions($options),
        ]));

        $prefix = $this->cacheConfig['prefix'];

        return "{$prefix}:{$provider}:{$model}:{$contentHash}";
    }

    /**
     * オプションを正規化（キャッシュキーの一貫性のため）
     */
    private function normalizeOptions(array $options): array
    {
        // 順序に依存しないようにソート
        ksort($options);

        // キャッシュに影響しないオプションを除外
        $excludeKeys = ['stream', 'async', 'timeout'];

        return array_diff_key($options, array_flip($excludeKeys));
    }

    /**
     * キャッシュが有効かチェック
     */
    private function isEnabled(): bool
    {
        return $this->cacheConfig['enabled'] ?? false;
    }

    /**
     * 適切なキャッシュストアを取得
     */
    private function getCacheStore(): CacheRepository
    {
        // GenAI専用のドライバーが設定されている場合はそれを使用
        if (isset($this->cacheConfig['driver'])) {
            $driver = $this->cacheConfig['driver'];

            // 指定されたドライバーが利用可能かチェック
            $availableStores = array_keys(config('cache.stores', []));
            if (in_array($driver, $availableStores)) {
                try {
                    return Cache::store($driver);
                } catch (\Exception $e) {
                    Log::warning("Failed to use cache driver '{$driver}': " . $e->getMessage());
                }
            }
        }

        return Cache::store();
    }

    /**
     * キャッシュ統計を取得
     */
    public function getStats(): array
    {
        $cache = $this->getCacheStore();
        $hits = 0;
        $misses = 0;

        try {
            $hits = $cache->get('genai:cache:hits', 0);
            $misses = $cache->get('genai:cache:misses', 0);
        } catch (\Exception $e) {
            // エラーの場合は0を返す
        }

        return [
            'enabled' => $this->isEnabled(),
            'driver' => $this->cacheConfig['driver'] ?? 'unknown',
            'ttl' => $this->cacheConfig['ttl'] ?? 3600,
            'prefix' => $this->cacheConfig['prefix'] ?? 'genai_cache',
            'tags_supported' => $this->supportsTagging(),
            'hits' => $hits,
            'misses' => $misses,
        ];
    }

    /**
     * キャッシュヒット率を計算（簡易版）
     */
    public function getHitRate(): float
    {
        $cache = $this->getCacheStore();
        try {
            $hits = $cache->get('genai:cache:hits', 0);
            $misses = $cache->get('genai:cache:misses', 0);
            $total = $hits + $misses;

            return $total > 0 ? $hits / $total : 0.0;
        } catch (\Exception $e) {
            Log::warning("Failed to get hit rate: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * キャッシュヒットを記録
     */
    public function recordHit(): void
    {
        try {
            $this->getCacheStore()->increment('genai:cache:hits');
        } catch (\Exception $e) {
            Log::warning("Failed to record cache hit: " . $e->getMessage());
        }
    }

    /**
     * キャッシュミスを記録
     */
    public function recordMiss(): void
    {
        try {
            $this->getCacheStore()->increment('genai:cache:misses');
        } catch (\Exception $e) {
            Log::warning("Failed to record cache miss: " . $e->getMessage());
        }
    }

    /**
     * キャッシュ容量をチェック（Redis用）
     */
    public function checkCapacity(): array
    {
        if ($this->cacheConfig['driver'] !== 'redis') {
            return ['available' => true, 'message' => 'Not Redis driver'];
        }

        try {
            $redis = Cache::getRedis();
            $info = $redis->info('memory');

            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;

            if ($maxMemory > 0) {
                $usagePercent = ($usedMemory / $maxMemory) * 100;
                $available = $usagePercent < 90;

                return [
                    'available' => $available,
                    'usage_percent' => round($usagePercent, 2),
                    'used_memory' => $usedMemory,
                    'max_memory' => $maxMemory,
                ];
            }

            return ['available' => true, 'message' => 'No memory limit set'];
        } catch (\Exception $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 全キャッシュをクリア
     */
    public function clearAll(): void
    {
        $this->flush();
    }

    /**
     * タグ機能をサポートするかチェック
     */
    private function supportsTagging(): bool
    {
        $driver = $this->cacheConfig['driver'] ?? 'redis';
        $hasTags = !empty($this->cacheConfig['tags']);

        // Redisドライバーのみタグ機能をサポート
        return $hasTags && $driver === 'redis';
    }

    /**
     * キーのメタデータを保存（将来的なタグ機能実装用）
     */
    private function storeKeyMetadata(string $key, string $provider, string $model): void
    {
        // 将来的にタグ機能を実装する際に使用
        // 現在は何もしない
    }

    /**
     * キーのメタデータを削除
     */
    private function removeKeyMetadata(string $key): void
    {
        // 将来的にタグ機能を実装する際に使用
        // 現在は何もしない
    }

    /**
     * すべてのキーメタデータをクリア
     */
    private function clearKeyMetadata(): void
    {
        // 将来的にタグ機能を実装する際に使用
        // 現在は何もしない
    }
}
