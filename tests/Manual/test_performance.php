<?php

require_once __DIR__.'/vendor/autoload.php';

use CattyNeo\LaravelGenAI\Facades\GenAI;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== GenAI パフォーマンステスト ===\n\n";

// テスト設定
$iterations = 5;
$testPrompts = [
    'short' => 'こんにちは',
    'medium' => '人工知能について簡潔に説明してください（100文字程度）',
    'long' => '日本の歴史について詳しく説明してください。古代から現代まで、主要な時代とその特徴を含めて詳細に記述してください。',
];

// 1. 基本パフォーマンステスト
echo "1. 基本パフォーマンステスト:\n";
foreach ($testPrompts as $type => $prompt) {
    echo "- {$type}プロンプト ({$iterations}回):\n";

    $times = [];
    $tokenCounts = [];
    $costs = [];

    for ($i = 1; $i <= $iterations; $i++) {
        try {
            $startTime = microtime(true);
            $response = GenAI::ask($prompt);
            $endTime = microtime(true);

            $time = ($endTime - $startTime) * 1000; // ms
            $times[] = $time;

            // 詳細情報を取得するため、requestメソッドでも実行
            $detailResponse = GenAI::prompt($prompt)->request();
            $tokenCounts[] = $detailResponse->usage['total_tokens'] ?? 0;
            $costs[] = $detailResponse->cost;

            echo "  反復 {$i}: ".number_format($time, 2)."ms\n";
        } catch (Exception $e) {
            echo "  反復 {$i}: エラー - ".$e->getMessage()."\n";
        }
    }

    if (! empty($times)) {
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $avgTokens = array_sum($tokenCounts) / count($tokenCounts);
        $avgCost = array_sum($costs) / count($costs);

        echo '  平均時間: '.number_format($avgTime, 2)."ms\n";
        echo '  最短時間: '.number_format($minTime, 2)."ms\n";
        echo '  最長時間: '.number_format($maxTime, 2)."ms\n";
        echo '  平均トークン: '.number_format($avgTokens, 0)."\n";
        echo '  平均コスト: $'.number_format($avgCost, 4)."\n";
        echo '  スループット: '.number_format(1000 / $avgTime, 2)." req/sec\n";
    }
    echo "\n";
}

// 2. プロバイダー比較テスト
echo "2. プロバイダー比較テスト:\n";
$providers = [
    'openai' => 'gpt-4.1-mini',
    'gemini' => 'gemini-2.0-flash-exp',
    'claude' => 'claude-3-5-sonnet-20241022',
    'grok' => 'grok-3-beta',
];

$testPrompt = '短い詩を作ってください';
$providerResults = [];

foreach ($providers as $provider => $model) {
    echo "- {$provider} ({$model}):\n";

    $times = [];
    $successCount = 0;

    for ($i = 1; $i <= $iterations; $i++) {
        try {
            $startTime = microtime(true);
            $response = GenAI::provider($provider)
                ->model($model)
                ->prompt($testPrompt)
                ->request();
            $endTime = microtime(true);

            $time = ($endTime - $startTime) * 1000;
            $times[] = $time;
            $successCount++;
        } catch (Exception $e) {
            echo "  反復 {$i}: エラー\n";
        }
    }

    if (! empty($times)) {
        $avgTime = array_sum($times) / count($times);
        $providerResults[$provider] = $avgTime;

        echo '  平均時間: '.number_format($avgTime, 2)."ms\n";
        echo '  成功率: '.number_format(($successCount / $iterations) * 100, 1)."%\n";
    }
    echo "\n";
}

// プロバイダー速度ランキング
if (! empty($providerResults)) {
    echo "プロバイダー速度ランキング:\n";
    asort($providerResults);
    $rank = 1;
    foreach ($providerResults as $provider => $time) {
        echo "{$rank}. {$provider}: ".number_format($time, 2)."ms\n";
        $rank++;
    }
    echo "\n";
}

// 3. キャッシュパフォーマンステスト
echo "3. キャッシュパフォーマンステスト:\n";

// キャッシュクリア
$cacheManager = app(\CattyNeo\LaravelGenAI\Services\GenAI\CacheManager::class);
$cacheManager->clearAll();

$cacheTestPrompt = 'キャッシュテスト用のプロンプトです';

// 初回リクエスト（キャッシュなし）
echo "- 初回リクエスト（キャッシュなし）:\n";
$cacheMissTimes = [];
for ($i = 1; $i <= 3; $i++) {
    $cacheManager->clearAll(); // 毎回クリア

    $startTime = microtime(true);
    $response = GenAI::prompt($cacheTestPrompt." {$i}")->request();
    $endTime = microtime(true);

    $time = ($endTime - $startTime) * 1000;
    $cacheMissTimes[] = $time;
    echo "  反復 {$i}: ".number_format($time, 2)."ms\n";
}

// キャッシュヒットテスト
echo "- キャッシュヒットテスト:\n";
$cacheHitTimes = [];
for ($i = 1; $i <= 3; $i++) {
    $startTime = microtime(true);
    $response = GenAI::prompt($cacheTestPrompt)->request();
    $endTime = microtime(true);

    $time = ($endTime - $startTime) * 1000;
    $cacheHitTimes[] = $time;
    echo "  反復 {$i}: ".number_format($time, 2)."ms\n";
}

$avgCacheMiss = array_sum($cacheMissTimes) / count($cacheMissTimes);
$avgCacheHit = array_sum($cacheHitTimes) / count($cacheHitTimes);
$speedup = $avgCacheMiss / $avgCacheHit;

echo '- キャッシュミス平均: '.number_format($avgCacheMiss, 2)."ms\n";
echo '- キャッシュヒット平均: '.number_format($avgCacheHit, 2)."ms\n";
echo '- キャッシュ効果: '.number_format($speedup, 2)."x 高速化\n\n";

// 4. 並行リクエストシミュレーション
echo "4. 並行リクエストシミュレーション:\n";
$concurrentPrompts = [
    '1 + 1 = ?',
    '今日の天気は？',
    'おすすめの本は？',
    'プログラミングとは？',
    'AIの未来は？',
];

echo "- 5つの異なるプロンプトを並行実行:\n";
$startTime = microtime(true);

$responses = [];
foreach ($concurrentPrompts as $index => $prompt) {
    try {
        $response = GenAI::prompt($prompt)->request();
        $responses[] = $response;
        echo '  プロンプト '.($index + 1).": 完了\n";
    } catch (Exception $e) {
        echo '  プロンプト '.($index + 1).": エラー\n";
    }
}

$totalTime = (microtime(true) - $startTime) * 1000;
$avgTimePerRequest = $totalTime / count($concurrentPrompts);

echo '- 総時間: '.number_format($totalTime, 2)."ms\n";
echo '- リクエスト当たり平均: '.number_format($avgTimePerRequest, 2)."ms\n";
echo '- 実効スループット: '.number_format(count($concurrentPrompts) / ($totalTime / 1000), 2)." req/sec\n\n";

// 5. メモリ使用量テスト
echo "5. メモリ使用量テスト:\n";
$initialMemory = memory_get_usage(true);
echo '- 初期メモリ使用量: '.number_format($initialMemory / 1024 / 1024, 2)." MB\n";

// 大量のリクエストを実行
for ($i = 1; $i <= 10; $i++) {
    GenAI::ask("テスト {$i}");
}

$finalMemory = memory_get_usage(true);
$peakMemory = memory_get_peak_usage(true);

echo '- 最終メモリ使用量: '.number_format($finalMemory / 1024 / 1024, 2)." MB\n";
echo '- ピークメモリ使用量: '.number_format($peakMemory / 1024 / 1024, 2)." MB\n";
echo '- メモリ増加量: '.number_format(($finalMemory - $initialMemory) / 1024 / 1024, 2)." MB\n\n";

// 6. 統計サマリー
echo "6. 統計サマリー:\n";
try {
    $stats = $cacheManager->getStats();
    echo '- キャッシュヒット数: '.$stats['hits']."\n";
    echo '- キャッシュミス数: '.$stats['misses']."\n";

    if ($stats['hits'] + $stats['misses'] > 0) {
        $hitRate = $stats['hits'] / ($stats['hits'] + $stats['misses']) * 100;
        echo '- キャッシュヒット率: '.number_format($hitRate, 1)."%\n";
    }
} catch (Exception $e) {
    echo '- 統計取得エラー: '.$e->getMessage()."\n";
}

echo "\n=== パフォーマンステスト完了 ===\n";
