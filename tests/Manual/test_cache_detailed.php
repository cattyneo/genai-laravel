<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use CattyNeo\LaravelGenAI\Facades\GenAI;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== GenAI キャッシュ機能詳細テスト ===\n\n";

// 1. キャッシュドライバーの確認
echo "1. キャッシュドライバー確認:\n";
echo "- デフォルトキャッシュ: " . config('cache.default') . "\n";
echo "- GenAIキャッシュ: " . config('genai.cache.driver') . "\n";
echo "- GenAIキャッシュ有効: " . (config('genai.cache.enabled') ? 'Yes' : 'No') . "\n\n";

// 2. Redisの動作確認
echo "2. Redis動作確認:\n";
try {
    $redis = Cache::store('redis');
    $redis->put('test_redis_connection', 'connected', 60);
    $result = $redis->get('test_redis_connection');
    echo "- Redis接続: " . ($result === 'connected' ? 'OK' : 'NG') . "\n";
    $redis->forget('test_redis_connection');
} catch (Exception $e) {
    echo "- Redis接続: NG - " . $e->getMessage() . "\n";
}

// 3. タグ機能サポート確認
echo "\n3. タグ機能サポート確認:\n";
try {
    $redisStore = Cache::store('redis');
    $fileStore = Cache::store('file');
    $databaseStore = Cache::store('database');

    echo "- Redis タグサポート: " . (method_exists($redisStore, 'tags') ? 'Yes' : 'No') . "\n";
    echo "- File タグサポート: " . (method_exists($fileStore, 'tags') ? 'Yes' : 'No') . "\n";
    echo "- Database タグサポート: " . (method_exists($databaseStore, 'tags') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "- タグサポート確認エラー: " . $e->getMessage() . "\n";
}

// 4. GenAIキャッシュ統計確認
echo "\n4. GenAIキャッシュ統計:\n";
try {
    $cacheManager = app(\CattyNeo\LaravelGenAI\Services\GenAI\CacheManager::class);
    $stats = $cacheManager->getStats();
    echo "- キャッシュ有効: " . ($stats['enabled'] ? 'Yes' : 'No') . "\n";
    echo "- ドライバー: " . $stats['driver'] . "\n";
    echo "- タグサポート: " . ($stats['tags_supported'] ? 'Yes' : 'No') . "\n";
    echo "- ヒット数: " . $stats['hits'] . "\n";
    echo "- ミス数: " . $stats['misses'] . "\n";
} catch (Exception $e) {
    echo "- キャッシュ統計取得エラー: " . $e->getMessage() . "\n";
}

// 5. 実際のキャッシュテスト
echo "\n5. 実際のキャッシュテスト:\n";

// 初回リクエスト（キャッシュなし）
$startTime = microtime(true);
try {
    $response1 = GenAI::ask('こんにちは！');
    $time1 = microtime(true) - $startTime;
    echo "- 初回リクエスト: " . number_format($time1 * 1000, 2) . "ms\n";
    echo "- レスポンス: " . substr($response1, 0, 50) . "...\n";
} catch (Exception $e) {
    echo "- 初回リクエストエラー: " . $e->getMessage() . "\n";
}

// 2回目リクエスト（キャッシュあり）
$startTime = microtime(true);
try {
    $response2 = GenAI::ask('こんにちは！');
    $time2 = microtime(true) - $startTime;
    echo "- 2回目リクエスト: " . number_format($time2 * 1000, 2) . "ms\n";
    echo "- レスポンス: " . substr($response2, 0, 50) . "...\n";
    echo "- キャッシュ効果: " . ($time2 < $time1 / 2 ? 'あり' : 'なし') . "\n";
} catch (Exception $e) {
    echo "- 2回目リクエストエラー: " . $e->getMessage() . "\n";
}

// 6. キャッシュクリアテスト
echo "\n6. キャッシュクリアテスト:\n";
try {
    $cacheManager = app(\CattyNeo\LaravelGenAI\Services\GenAI\CacheManager::class);
    $cacheManager->clearAll();
    echo "- キャッシュクリア: 完了\n";

    // クリア後の統計確認
    $stats = $cacheManager->getStats();
    echo "- クリア後 ヒット数: " . $stats['hits'] . "\n";
    echo "- クリア後 ミス数: " . $stats['misses'] . "\n";
} catch (Exception $e) {
    echo "- キャッシュクリアエラー: " . $e->getMessage() . "\n";
}

// 7. Redis直接確認
echo "\n7. Redis直接確認:\n";
try {
    exec('redis-cli keys "genai_cache*" | wc -l', $output);
    echo "- GenAIキャッシュキー数: " . trim($output[0]) . "\n";
} catch (Exception $e) {
    echo "- Redis直接確認エラー: " . $e->getMessage() . "\n";
}

echo "\n=== キャッシュテスト完了 ===\n";
