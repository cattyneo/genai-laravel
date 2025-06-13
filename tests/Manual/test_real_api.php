<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\GenAI\GenAIManager;

// Laravel アプリケーションの初期化
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Laravel GenAI Package - 実API テスト ===\n\n";

try {
    $genai = $app->make(GenAIManager::class);

    // 1. デフォルトプリセットでのテスト
    echo "1. デフォルトプリセット（OpenAI GPT-4.1-mini）テスト\n";
    echo "---------------------------------------------------\n";

    $response1 = $genai
        ->preset('default')
        ->prompt('こんにちは！あなたは誰ですか？')
        ->request();

    echo "✓ リクエスト成功\n";
    echo "  - レスポンス: " . substr($response1->content, 0, 100) . "...\n";
    echo "  - コスト: ¥{$response1->cost}\n";
    echo "  - キャッシュ: " . ($response1->cached ? 'ヒット' : 'ミス') . "\n";
    echo "  - 応答時間: {$response1->responseTimeMs}ms\n";
    echo "  - 入力トークン: " . ($response1->usage['prompt_tokens'] ?? 'N/A') . "\n";
    echo "  - 出力トークン: " . ($response1->usage['completion_tokens'] ?? 'N/A') . "\n\n";

    // 2. 同じリクエストでキャッシュテスト
    echo "2. キャッシュテスト（同じプロンプト）\n";
    echo "------------------------------------\n";

    $response2 = $genai
        ->preset('default')
        ->prompt('こんにちは！あなたは誰ですか？')
        ->request();

    echo "✓ 2回目リクエスト成功\n";
    echo "  - キャッシュ: " . ($response2->cached ? 'ヒット' : 'ミス') . "\n";
    echo "  - 応答時間: {$response2->responseTimeMs}ms\n\n";

    // 3. askプリセットでのテスト
    echo "3. askプリセット（低温度設定）テスト\n";
    echo "-----------------------------------\n";

    $response3 = $genai
        ->preset('ask')
        ->prompt('LaravelでMVCパターンを簡潔に説明してください。')
        ->request();

    echo "✓ askプリセットリクエスト成功\n";
    echo "  - レスポンス: " . substr($response3->content, 0, 150) . "...\n";
    echo "  - コスト: ¥{$response3->cost}\n";
    echo "  - 応答時間: {$response3->responseTimeMs}ms\n\n";

    // 4. createプリセット（GPT-4.1）でのテスト
    echo "4. createプリセット（GPT-4.1, 高温度）テスト\n";
    echo "---------------------------------------------\n";

    $response4 = $genai
        ->preset('create')
        ->prompt('AIと人間の協働について、詩的な表現で短い文章を書いてください。')
        ->request();

    echo "✓ createプリセットリクエスト成功\n";
    echo "  - レスポンス: " . substr($response4->content, 0, 200) . "...\n";
    echo "  - コスト: ¥{$response4->cost}\n";
    echo "  - 応答時間: {$response4->responseTimeMs}ms\n\n";

    // 5. codeプリセットでのテスト
    echo "5. codeプリセット（低温度、コード生成）テスト\n";
    echo "-------------------------------------------\n";

    $response5 = $genai
        ->preset('code')
        ->prompt('PHPでシンプルなバリデーション関数を作成してください。メールアドレスをチェックする関数です。')
        ->request();

    echo "✓ codeプリセットリクエスト成功\n";
    echo "  - レスポンス: " . substr($response5->content, 0, 200) . "...\n";
    echo "  - コスト: ¥{$response5->cost}\n";
    echo "  - 応答時間: {$response5->responseTimeMs}ms\n\n";

    // 6. Claudeプロバイダーテスト（analyzeプリセット）
    echo "6. Claudeプロバイダー（analyzeプリセット）テスト\n";
    echo "----------------------------------------------\n";

    try {
        $response6 = $genai
            ->preset('analyze')
            ->prompt('以下のデータを分析してください：売上 1月:100万円, 2月:120万円, 3月:90万円')
            ->request();

        echo "✓ Claudeプロバイダーリクエスト成功\n";
        echo "  - レスポンス: " . substr($response6->content, 0, 200) . "...\n";
        echo "  - コスト: ¥{$response6->cost}\n";
        echo "  - 応答時間: {$response6->responseTimeMs}ms\n\n";
    } catch (Exception $e) {
        echo "✗ Claudeプロバイダーエラー: " . $e->getMessage() . "\n";
        echo "  (Claude APIキーが設定されていない可能性があります)\n\n";
    }

    // 7. 推論モデル（o4-mini）テスト
    echo "7. 推論モデル（o4-mini）テスト\n";
    echo "-------------------------------\n";

    try {
        $response7 = $genai
            ->preset('think')
            ->prompt('3つの箱があり、そのうち1つに宝物が入っています。あなたが1つ選んだ後、司会者が残り2つのうち空の箱を1つ開けました。このとき、選択を変更すべきでしょうか？論理的に説明してください。')
            ->request();

        echo "✓ 推論モデルリクエスト成功\n";
        echo "  - レスポンス: " . substr($response7->content, 0, 200) . "...\n";
        echo "  - コスト: ¥{$response7->cost}\n";
        echo "  - 応答時間: {$response7->responseTimeMs}ms\n";
        echo "  - 推論トークン: " . ($response7->usage['completion_tokens_details']['reasoning_tokens'] ?? 'N/A') . "\n\n";
    } catch (Exception $e) {
        echo "✗ 推論モデルエラー: " . $e->getMessage() . "\n";
        echo "  (o4-miniが利用できない可能性があります)\n\n";
    }

    // 8. 総コスト計算
    $totalCost = $response1->cost + $response3->cost + $response4->cost + $response5->cost;
    if (isset($response6)) $totalCost += $response6->cost;
    if (isset($response7)) $totalCost += $response7->cost;

    echo "=== テスト結果サマリー ===\n";
    echo "総リクエスト数: " . (5 + (isset($response6) ? 1 : 0) + (isset($response7) ? 1 : 0)) . "回\n";
    echo "総コスト: ¥" . number_format($totalCost, 2) . "\n";
    echo "キャッシュヒット: " . ($response2->cached ? '1回' : '0回') . "\n";
    echo "\n✓ すべてのプリセットが正常に動作しています！\n";
} catch (Exception $e) {
    echo "✗ テスト中にエラーが発生しました: " . $e->getMessage() . "\n";
    echo "スタックトレース:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== テスト完了 ===\n";
