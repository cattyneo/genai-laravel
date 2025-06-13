<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Services\GenAI\CacheManager;
use App\Services\GenAI\GenAIManager;

// Laravel アプリケーションの初期化
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Redis性能比較テスト ===\n\n";

function runPerformanceTest($client, $iterations = 10)
{
    echo "=== {$client}性能テスト ===\n";

    $genai = app(GenAIManager::class);
    $cacheManager = app(CacheManager::class);

    // キャッシュをクリア
    $cacheManager->flush();

    $times = [];
    $costs = [];

    // ウォームアップ
    $genai->preset('ask')->prompt('テスト用プロンプト')->request();

    for ($i = 1; $i <= $iterations; $i++) {
        echo "テスト {$i}/{$iterations}... ";

        $startTime = microtime(true);

        $response = $genai
            ->preset('ask')
            ->prompt("テスト用プロンプト {$i}: 簡潔に回答してください。")
            ->request();

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // ms

        $times[] = $duration;
        $costs[] = $response->cost;

        echo sprintf(
            "%.1fms (¥%.4f) %s\n",
            $duration,
            $response->cost,
            $response->cached ? '[CACHE]' : '[API]'
        );

        usleep(100000); // 100ms待機
    }

    echo "\n--- 統計 ---\n";
    echo sprintf("平均応答時間: %.1fms\n", array_sum($times) / count($times));
    echo sprintf("最短応答時間: %.1fms\n", min($times));
    echo sprintf("最長応答時間: %.1fms\n", max($times));
    echo sprintf("総コスト: ¥%.4f\n", array_sum($costs));
    echo sprintf("平均コスト: ¥%.4f\n", array_sum($costs) / count($costs));
    echo sprintf(
        "キャッシュヒット率: %.1f%%\n",
        (count(array_filter($costs, fn ($c) => $c == 0)) / count($costs)) * 100
    );
    echo "\n";

    return [
        'avg_time' => array_sum($times) / count($times),
        'min_time' => min($times),
        'max_time' => max($times),
        'total_cost' => array_sum($costs),
        'cache_hit_rate' => (count(array_filter($costs, fn ($c) => $c == 0)) / count($costs)) * 100,
    ];
}

try {
    // PhpRedis テスト
    putenv('REDIS_CLIENT=phpredis');
    putenv('CACHE_STORE=redis');
    echo "PhpRedis設定でテスト開始...\n\n";
    $phpredisResults = runPerformanceTest('PhpRedis', 15);

    // 少し待機
    sleep(2);

    // Predis テスト
    putenv('REDIS_CLIENT=predis');
    echo "Predis設定でテスト開始...\n\n";
    $predisResults = runPerformanceTest('Predis', 15);

    // 比較結果
    echo "=== 性能比較結果 ===\n";
    echo sprintf("PhpRedis平均応答時間: %.1fms\n", $phpredisResults['avg_time']);
    echo sprintf("Predis平均応答時間: %.1fms\n", $predisResults['avg_time']);
    echo sprintf(
        "性能向上: %.1fx faster\n",
        $predisResults['avg_time'] / $phpredisResults['avg_time']
    );
    echo "\n";

    echo sprintf("PhpRedis最短応答: %.1fms\n", $phpredisResults['min_time']);
    echo sprintf("Predis最短応答: %.1fms\n", $predisResults['min_time']);
    echo "\n";

    echo sprintf("PhpRedisキャッシュヒット率: %.1f%%\n", $phpredisResults['cache_hit_rate']);
    echo sprintf("Predisキャッシュヒット率: %.1f%%\n", $predisResults['cache_hit_rate']);
    echo "\n";

    // 推奨設定
    $faster = $phpredisResults['avg_time'] < $predisResults['avg_time'] ? 'PhpRedis' : 'Predis';
    echo "=== 推奨設定 ===\n";
    echo "最適なRedisクライアント: {$faster}\n";
    echo '設定: REDIS_CLIENT='.strtolower($faster)."\n";

    // Redis拡張確認
    echo "\n=== Redis 環境情報 ===\n";
    echo 'PHP Redis拡張: '.(extension_loaded('redis') ? '✓ 有効' : '✗ 無効')."\n";
    echo 'Predisパッケージ: '.(class_exists('Predis\Client') ? '✓ インストール済み' : '✗ 未インストール')."\n";
} catch (Exception $e) {
    echo 'エラーが発生しました: '.$e->getMessage()."\n";
    echo "スタックトレース:\n".$e->getTraceAsString()."\n";
}

echo "\n=== 性能テスト完了 ===\n";
