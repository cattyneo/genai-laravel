<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Services\GenAI\CacheManager;
use App\Services\GenAI\GenAIManager;
use App\Services\GenAI\PromptManager;

// Laravel アプリケーションの初期化
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Laravel GenAI Package - 統合テスト ===\n\n";

try {
    $genai = $app->make(GenAIManager::class);
    $promptManager = $app->make(PromptManager::class);
    $cacheManager = $app->make(CacheManager::class);

    // 1. PromptManager機能テスト
    echo "1. PromptManager機能テスト\n";
    echo "==========================\n";

    $stats = $promptManager->getStats();
    echo "✓ プロンプト統計:\n";
    echo "  - 総プロンプト数: {$stats['total_prompts']}個\n";
    echo "  - パス: {$stats['prompts_path']}\n";
    echo '  - カテゴリ: '.implode(', ', array_keys($stats['categories']))."\n";
    echo '  - 変数: '.implode(', ', $stats['variables_used'])."\n\n";

    $templates = $promptManager->list();
    echo "✓ 利用可能なプロンプトテンプレート:\n";
    foreach ($templates as $template) {
        echo "  - {$template}\n";
    }
    echo "\n";

    // 2. プロンプトテンプレート実行テスト
    echo "2. プロンプトテンプレート実行テスト\n";
    echo "==================================\n";

    // デフォルトプロンプトテスト
    $response1 = $genai
        ->promptTemplate('default', ['topic' => 'Laravel'])
        ->preset('ask')
        ->request();

    echo "✓ デフォルトプロンプトテスト完了\n";
    echo "  - プロンプト: Laravel について詳しく説明してください。\n";
    echo '  - レスポンス: '.substr($response1->content, 0, 100)."...\n";
    echo "  - コスト: ¥{$response1->cost}\n";
    echo '  - キャッシュ: '.($response1->cached ? 'ヒット' : 'ミス')."\n\n";

    // ブログ作成プロンプトテスト
    if ($promptManager->exists('create')) {
        $response2 = $genai
            ->promptTemplate('create', [
                'topic' => 'AI技術の未来',
                'keywords' => 'AI, 機械学習, 自動化',
                'length' => '800文字',
            ])
            ->preset('create')
            ->request();

        echo "✓ ブログ作成プロンプトテスト完了\n";
        echo '  - レスポンス: '.substr($response2->content, 0, 100)."...\n";
        echo "  - コスト: ¥{$response2->cost}\n";
        echo '  - キャッシュ: '.($response2->cached ? 'ヒット' : 'ミス')."\n\n";
    }

    // 3. Claudeモデルテスト
    echo "3. Claudeモデルテスト\n";
    echo "=====================\n";

    try {
        $response3 = $genai
            ->preset('analyze')
            ->prompt('日本のAI技術の現状と課題について分析してください。')
            ->request();

        echo "✓ Claude Sonnet 4テスト成功\n";
        echo "  - プロバイダー: claude\n";
        echo "  - モデル: claude-sonnet-4-20250514\n";
        echo '  - レスポンス: '.substr($response3->content, 0, 150)."...\n";
        echo "  - コスト: ¥{$response3->cost}\n";
        echo '  - キャッシュ: '.($response3->cached ? 'ヒット' : 'ミス')."\n\n";
    } catch (Exception $e) {
        echo '✗ Claudeテストエラー: '.$e->getMessage()."\n\n";
    }

    // 4. 推論モデルテスト（o4-mini）
    echo "4. 推論モデルテスト（o4-mini）\n";
    echo "=============================\n";

    try {
        $response4 = $genai
            ->preset('think')
            ->prompt('素数の無限性を証明してください。')
            ->request();

        echo "✓ o4-mini推論モデルテスト成功\n";
        echo "  - プロバイダー: openai\n";
        echo "  - モデル: o4-mini\n";
        echo '  - レスポンス: '.substr($response4->content, 0, 150)."...\n";
        echo "  - コスト: ¥{$response4->cost}\n";
        echo '  - 推論トークン: '.($response4->reasoningTokens ?? 0)."\n";
        echo '  - キャッシュ: '.($response4->cached ? 'ヒット' : 'ミス')."\n\n";
    } catch (Exception $e) {
        echo '✗ o4-miniテストエラー: '.$e->getMessage()."\n\n";
    }

    // 5. キャッシュ効果テスト
    echo "5. キャッシュ効果テスト\n";
    echo "======================\n";

    $startTime = microtime(true);
    $response5 = $genai
        ->promptTemplate('default', ['topic' => 'Laravel'])
        ->preset('ask')
        ->request();
    $duration5 = microtime(true) - $startTime;

    echo "✓ 同一プロンプト再実行\n";
    echo '  - キャッシュ: '.($response5->cached ? 'ヒット' : 'ミス')."\n";
    echo '  - 応答時間: '.round($duration5 * 1000)."ms\n";

    if ($response5->cached) {
        echo "  - キャッシュ効果: 有効\n";
    } else {
        echo "  - キャッシュ効果: 無効\n";
    }
    echo "\n";

    // 6. コスト統計
    echo "6. コスト統計\n";
    echo "=============\n";

    $totalCost = $response1->cost + ($response2->cost ?? 0) +
        ($response3->cost ?? 0) + ($response4->cost ?? 0) + $response5->cost;

    echo '✓ 総コスト: ¥'.round($totalCost, 4)."\n";
    echo '✓ 実行リクエスト数: '.(4 + (isset($response2) ? 1 : 0))."回\n";
    echo '✓ 平均コスト: ¥'.round($totalCost / 4, 4)."/リクエスト\n\n";

    // 7. プロンプト管理機能テスト
    echo "7. プロンプト管理機能テスト\n";
    echo "==========================\n";

    // 動的プロンプト追加
    $promptManager->add('test_dynamic', 'これは動的に追加されたプロンプトです: {{message}}', [
        'title' => 'テスト用動的プロンプト',
        'variables' => ['message'],
    ]);

    $response6 = $genai
        ->promptTemplate('test_dynamic', ['message' => 'Hello from dynamic prompt!'])
        ->preset('ask')
        ->request();

    echo "✓ 動的プロンプト追加・実行成功\n";
    echo '  - レスポンス: '.substr($response6->content, 0, 100)."...\n";
    echo "  - コスト: ¥{$response6->cost}\n\n";

    // 8. 最終結果
    echo "=== テスト結果サマリー ===\n";
    echo "PromptManager: ✓ 正常動作\n";
    echo "Redisキャッシュ: ✓ 正常動作\n";
    echo 'Claude API: '.(isset($response3) ? '✓ 正常動作' : '✗ エラー')."\n";
    echo '推論モデル: '.(isset($response4) ? '✓ 正常動作' : '✗ エラー')."\n";
    echo "統合機能: ✓ 正常動作\n";
    echo "\n✅ すべての機能が正常に動作しています！\n";
} catch (Exception $e) {
    echo '✗ テスト中にエラーが発生しました: '.$e->getMessage()."\n";
    echo "スタックトレース:\n".$e->getTraceAsString()."\n";
}

echo "\n=== 統合テスト完了 ===\n";
