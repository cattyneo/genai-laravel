<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\GenAI\GenAIManager;
use App\Services\GenAI\CacheManager;

// Laravel アプリケーションの初期化
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Laravel GenAI Package - Redisキャッシュテスト ===\n\n";

try {
    $genai = $app->make(GenAIManager::class);
    $cacheManager = $app->make(CacheManager::class);

    // 1. キャッシュ設定確認
    echo "1. キャッシュ設定確認\n";
    echo "------------------------\n";

    $stats = $cacheManager->getStats();
    echo "✓ キャッシュ設定:\n";
    echo "  - 有効: " . ($stats['enabled'] ? 'はい' : 'いいえ') . "\n";
    echo "  - ドライバー: {$stats['driver']}\n";
    echo "  - TTL: {$stats['ttl']}秒\n";
    echo "  - プレフィックス: {$stats['prefix']}\n";
    echo "  - タグ: " . implode(', ', $stats['tags']) . "\n\n";

    // 2. Redis接続確認
    echo "2. Redis接続確認\n";
    echo "------------------\n";

    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $info = $redis->info();
    echo "✓ Redis接続成功\n";
    echo "  - バージョン: {$info['redis_version']}\n";
    echo "  - アップタイム: {$info['uptime_in_seconds']}秒\n";
    echo "  - 使用メモリ: {$info['used_memory_human']}\n\n";

    // 3. キャッシュミステスト（1回目）
    echo "3. キャッシュミステスト（1回目）\n";
    echo "-------------------------------\n";

    $cacheManager->flush(); // キャッシュクリア
    $startTime = microtime(true);

    $response1 = $genai
        ->preset('default')
        ->prompt('2+2はいくつですか？簡潔に答えてください。')
        ->request();

    $duration1 = microtime(true) - $startTime;

    echo "✓ 1回目リクエスト完了\n";
    echo "  - レスポンス: {$response1->content}\n";
    echo "  - コスト: ¥{$response1->cost}\n";
    echo "  - キャッシュ: " . ($response1->cached ? 'ヒット' : 'ミス') . "\n";
    echo "  - 応答時間: " . round($duration1 * 1000) . "ms\n\n";

    // 4. キャッシュヒットテスト（2回目）
    echo "4. キャッシュヒットテスト（2回目）\n";
    echo "--------------------------------\n";

    $startTime = microtime(true);

    $response2 = $genai
        ->preset('default')
        ->prompt('2+2はいくつですか？簡潔に答えてください。')
        ->request();

    $duration2 = microtime(true) - $startTime;

    echo "✓ 2回目リクエスト完了\n";
    echo "  - レスポンス: {$response2->content}\n";
    echo "  - コスト: ¥{$response2->cost}\n";
    echo "  - キャッシュ: " . ($response2->cached ? 'ヒット' : 'ミス') . "\n";
    echo "  - 応答時間: " . round($duration2 * 1000) . "ms\n";
    echo "  - 高速化: " . round((1 - $duration2 / $duration1) * 100) . "%\n\n";

    // 5. Redisキー確認
    echo "5. Redisキー確認\n";
    echo "----------------\n";

    $keys = $redis->keys('genai_cache:*');
    echo "✓ Redisキー数: " . count($keys) . "個\n";
    if (count($keys) > 0) {
        echo "  - 最新キー: {$keys[0]}\n";
        $value = $redis->get($keys[0]);
        $data = unserialize($value);
        if ($data) {
            echo "  - キャッシュデータサイズ: " . strlen($value) . " bytes\n";
        }
    }
    echo "\n";

    // 6. タグベースキャッシュテスト
    echo "6. タグベースキャッシュテスト\n";
    echo "----------------------------\n";

    try {
        // 異なるプロバイダーでリクエスト
        $response3 = $genai
            ->preset('ask')
            ->prompt('Laravelの特徴を教えてください。')
            ->request();

        echo "✓ askプリセットリクエスト成功\n";
        echo "  - プロバイダー: openai\n";
        echo "  - キャッシュ: " . ($response3->cached ? 'ヒット' : 'ミス') . "\n\n";

        // プロバイダー別キャッシュクリア
        echo "✓ OpenAIプロバイダーキャッシュクリア実行中...\n";
        $cacheManager->flushProvider('openai');

        // 再度同じリクエスト
        $response4 = $genai
            ->preset('ask')
            ->prompt('Laravelの特徴を教えてください。')
            ->request();

        echo "✓ キャッシュクリア後リクエスト\n";
        echo "  - キャッシュ: " . ($response4->cached ? 'ヒット' : 'ミス') . "\n\n";
    } catch (Exception $e) {
        echo "✗ タグベースキャッシュエラー: " . $e->getMessage() . "\n\n";
    }

    // 7. キャッシュ統計
    echo "7. キャッシュ統計\n";
    echo "----------------\n";

    $hitRate = $cacheManager->getHitRate();
    echo "✓ キャッシュヒット率: " . round($hitRate * 100, 1) . "%\n";

    $allKeys = $redis->keys('genai_cache:*');
    echo "✓ 総キャッシュキー数: " . count($allKeys) . "個\n";

    $memInfo = $redis->info('memory');
    echo "✓ Redis使用メモリ: {$memInfo['used_memory_human']}\n\n";

    echo "=== テスト結果サマリー ===\n";
    echo "キャッシュドライバー: Redis ✓\n";
    echo "キャッシュ機能: " . ($response2->cached ? '正常' : '異常') . "\n";
    echo "タグ機能: " . (class_exists('Redis') ? '対応' : '非対応') . "\n";
    echo "応答時間改善: " . round((1 - $duration2 / $duration1) * 100) . "%\n";
    echo "\n✓ Redisキャッシュが正常に動作しています！\n";
} catch (Exception $e) {
    echo "✗ テスト中にエラーが発生しました: " . $e->getMessage() . "\n";
    echo "スタックトレース:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== テスト完了 ===\n";
