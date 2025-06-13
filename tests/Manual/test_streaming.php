<?php

require_once __DIR__ . '/vendor/autoload.php';

use CattyNeo\LaravelGenAI\Facades\GenAI;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== GenAI ストリーミング機能テスト ===\n\n";

// 1. 基本的なストリーミングテスト
echo "1. 基本的なストリーミングテスト:\n";
try {
    echo "- プロンプト: 「1から10まで数えてください」\n";
    echo "- ストリーミング開始...\n";
    echo "- レスポンス: ";

    $startTime = microtime(true);
    $response = GenAI::stream()
        ->prompt("1から10まで数えてください。各数字の後に改行を入れてください。")
        ->request();
    $time = microtime(true) - $startTime;

    echo "\n- ストリーミング完了\n";
    echo "- 総時間: " . number_format($time * 1000, 2) . "ms\n";
    echo "- 最終レスポンス: " . substr($response->content, 0, 100) . "...\n";
    echo "- トークン使用量: " . ($response->usage['total_tokens'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "\n- エラー: " . $e->getMessage() . "\n";
}

echo "\n";

// 2. 異なるプロバイダーでのストリーミング
$providers = [
    'openai' => 'gpt-4.1-mini',
    'gemini' => 'gemini-2.0-flash-exp',
    'claude' => 'claude-3-5-sonnet-20241022',
    'grok' => 'grok-3-beta'
];

echo "2. 異なるプロバイダーでのストリーミング:\n";
foreach ($providers as $provider => $model) {
    echo "- {$provider} ({$model}):\n";
    try {
        $startTime = microtime(true);
        $response = GenAI::provider($provider)
            ->model($model)
            ->stream()
            ->prompt("短い詩を作ってください（3行程度）")
            ->request();
        $time = microtime(true) - $startTime;

        echo "  ✓ 成功 ({$time}ms)\n";
        echo "  レスポンス: " . substr(str_replace("\n", " ", $response->content), 0, 80) . "...\n";
    } catch (Exception $e) {
        echo "  ✗ エラー: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// 3. 長いコンテンツのストリーミング
echo "3. 長いコンテンツのストリーミング:\n";
try {
    echo "- プロンプト: 「日本の四季について詳しく説明してください」\n";
    echo "- ストリーミング開始...\n";

    $startTime = microtime(true);
    $chunkCount = 0;
    $totalLength = 0;

    $response = GenAI::stream()
        ->maxTokens(500)
        ->prompt("日本の四季について詳しく説明してください。春、夏、秋、冬それぞれの特徴を含めてください。")
        ->request();

    $time = microtime(true) - $startTime;
    $totalLength = strlen($response->content);

    echo "\n- ストリーミング完了\n";
    echo "- 総時間: " . number_format($time * 1000, 2) . "ms\n";
    echo "- 総文字数: {$totalLength}文字\n";
    echo "- 平均速度: " . number_format($totalLength / $time, 2) . " 文字/秒\n";
    echo "- トークン使用量: " . ($response->usage['total_tokens'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "\n- エラー: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. ストリーミング vs 通常リクエストの比較
echo "4. ストリーミング vs 通常リクエストの比較:\n";
$testPrompt = "人工知能の歴史について簡潔に説明してください（200文字程度）";

// 通常リクエスト
echo "- 通常リクエスト:\n";
try {
    $startTime = microtime(true);
    $normalResponse = GenAI::prompt($testPrompt)->request();
    $normalTime = microtime(true) - $startTime;

    echo "  時間: " . number_format($normalTime * 1000, 2) . "ms\n";
    echo "  文字数: " . strlen($normalResponse->content) . "文字\n";
} catch (Exception $e) {
    echo "  エラー: " . $e->getMessage() . "\n";
}

// ストリーミングリクエスト
echo "- ストリーミングリクエスト:\n";
try {
    $startTime = microtime(true);
    $streamResponse = GenAI::stream()->prompt($testPrompt)->request();
    $streamTime = microtime(true) - $startTime;

    echo "  時間: " . number_format($streamTime * 1000, 2) . "ms\n";
    echo "  文字数: " . strlen($streamResponse->content) . "文字\n";

    if ($normalTime > 0 && $streamTime > 0) {
        $speedup = $normalTime / $streamTime;
        echo "  速度比較: " . number_format($speedup, 2) . "x " .
            ($speedup > 1 ? "ストリーミングが高速" : "通常が高速") . "\n";
    }
} catch (Exception $e) {
    echo "  エラー: " . $e->getMessage() . "\n";
}

echo "\n";

// 5. エラーハンドリングテスト
echo "5. ストリーミングエラーハンドリング:\n";

// 無効なモデル
echo "- 無効なモデルテスト:\n";
try {
    $response = GenAI::provider('openai')
        ->model('invalid-model')
        ->stream()
        ->prompt("テスト")
        ->request();
    echo "  予期しない成功\n";
} catch (Exception $e) {
    echo "  ✓ 期待通りのエラー: " . substr($e->getMessage(), 0, 100) . "...\n";
}

// 空のプロンプト
echo "- 空のプロンプトテスト:\n";
try {
    $response = GenAI::stream()
        ->prompt("")
        ->request();
    echo "  予期しない成功\n";
} catch (Exception $e) {
    echo "  ✓ 期待通りのエラー: " . substr($e->getMessage(), 0, 100) . "...\n";
}

echo "\n";

// 6. パフォーマンス統計
echo "6. ストリーミングパフォーマンス統計:\n";
$iterations = 3;
$totalTime = 0;
$successCount = 0;

for ($i = 1; $i <= $iterations; $i++) {
    echo "- 反復 {$i}/{$iterations}: ";
    try {
        $startTime = microtime(true);
        $response = GenAI::stream()
            ->prompt("こんにちは！元気ですか？")
            ->request();
        $time = microtime(true) - $startTime;

        $totalTime += $time;
        $successCount++;
        echo number_format($time * 1000, 2) . "ms ✓\n";
    } catch (Exception $e) {
        echo "エラー ✗\n";
    }
}

if ($successCount > 0) {
    $avgTime = $totalTime / $successCount;
    echo "- 平均時間: " . number_format($avgTime * 1000, 2) . "ms\n";
    echo "- 成功率: " . number_format(($successCount / $iterations) * 100, 1) . "%\n";
}

echo "\n=== ストリーミングテスト完了 ===\n";
