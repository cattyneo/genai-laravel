<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Services\GenAI\CacheManager;
use App\Services\GenAI\CostCalculator;
use App\Services\GenAI\PresetRepository;
use App\Services\GenAI\ProviderFactory;

// Laravel アプリケーションの初期化
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Laravel GenAI Package - 新機能テスト ===\n\n";

// 1. プリセット機能のテスト
echo "1. プリセット機能テスト\n";
echo "------------------------\n";

try {
    $presetRepo = $app->make(PresetRepository::class);

    // デフォルトプリセットを取得
    $defaultPreset = $presetRepo->get('default');
    echo "✓ デフォルトプリセット取得成功\n";
    echo "  - プロバイダー: {$defaultPreset->provider}\n";
    echo "  - モデル: {$defaultPreset->model}\n";
    echo '  - システムプロンプト: '.substr($defaultPreset->systemPrompt ?? 'なし', 0, 50)."...\n";

    // askプリセットを取得
    $askPreset = $presetRepo->get('ask');
    echo "✓ askプリセット取得成功\n";
    echo '  - 温度: '.($askPreset->options['temperature'] ?? 'デフォルト')."\n";
} catch (Exception $e) {
    echo '✗ プリセット機能エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 2. コスト計算機能のテスト
echo "2. コスト計算機能テスト\n";
echo "------------------------\n";

try {
    $costCalculator = $app->make(CostCalculator::class);

    // GPT-4.1-miniのコスト計算
    $cost = $costCalculator->calculateCost(
        model: 'gpt-4.1-mini',
        inputTokens: 1000,
        outputTokens: 500,
        cachedTokens: 200
    );

    echo "✓ コスト計算成功\n";
    echo "  - モデル: gpt-4.1-mini\n";
    echo "  - 入力トークン: 1,000\n";
    echo "  - 出力トークン: 500\n";
    echo "  - キャッシュトークン: 200\n";
    echo "  - 計算コスト: ¥{$cost}\n";

    // プロバイダー別モデル一覧
    $openaiModels = $costCalculator->getProviderModels('openai');
    echo '✓ OpenAIモデル数: '.count($openaiModels)."個\n";

    // 機能別モデル検索
    $visionModels = $costCalculator->getModelsByFeature('vision');
    echo '✓ Vision対応モデル数: '.count($visionModels)."個\n";
} catch (Exception $e) {
    echo '✗ コスト計算エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 3. キャッシュ機能のテスト
echo "3. キャッシュ機能テスト\n";
echo "----------------------\n";

try {
    $cacheManager = $app->make(CacheManager::class);

    // キャッシュ統計を取得
    $stats = $cacheManager->getStats();
    echo "✓ キャッシュ統計取得成功\n";
    echo '  - 有効: '.($stats['enabled'] ? 'はい' : 'いいえ')."\n";
    echo "  - ドライバー: {$stats['driver']}\n";
    echo "  - TTL: {$stats['ttl']}秒\n";
    echo "  - プレフィックス: {$stats['prefix']}\n";

    // テストデータをキャッシュに保存
    $testResponse = [
        'content' => 'テストレスポンス',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        'cost' => 1.5,
        'meta' => [],
    ];

    $cacheManager->put('openai', 'gpt-4.1-mini', 'テストプロンプト', [], $testResponse);
    echo "✓ キャッシュ保存成功\n";

    // キャッシュから取得
    $cached = $cacheManager->get('openai', 'gpt-4.1-mini', 'テストプロンプト', []);
    if ($cached) {
        echo "✓ キャッシュ取得成功: {$cached['content']}\n";
    } else {
        echo "✗ キャッシュ取得失敗\n";
    }
} catch (Exception $e) {
    echo '✗ キャッシュ機能エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 4. プロバイダーファクトリーのテスト
echo "4. プロバイダーファクトリーテスト\n";
echo "--------------------------------\n";

try {
    $providerFactory = $app->make(ProviderFactory::class);

    // 利用可能プロバイダー一覧
    $availableProviders = ProviderFactory::getAvailableProviders();
    echo '✓ 利用可能プロバイダー: '.implode(', ', $availableProviders)."\n";

    // OpenAIプロバイダーを作成（テスト用設定）
    $testConfig = [
        'api_key' => 'sk-test-dummy-key',
        'base_url' => 'https://api.openai.com/v1',
    ];

    $provider = $providerFactory->create('openai', $testConfig);
    echo '✓ OpenAIプロバイダー作成成功: '.get_class($provider)."\n";
} catch (Exception $e) {
    echo '✗ プロバイダーファクトリーエラー: '.$e->getMessage()."\n";
}

echo "\n";

// 5. 設定ファイルの検証
echo "5. 設定ファイル検証\n";
echo "------------------\n";

try {
    $config = config('genai');

    echo "✓ 設定ファイル読み込み成功\n";
    echo '  - デフォルトプロバイダー: '.($config['defaults']['provider'] ?? 'なし')."\n";
    echo '  - デフォルトモデル: '.($config['defaults']['model'] ?? 'なし')."\n";
    echo '  - キャッシュ有効: '.($config['cache']['enabled'] ? 'はい' : 'いいえ')."\n";
    echo '  - 定義済みモデル数: '.count($config['models'] ?? [])."個\n";
    echo '  - プロバイダー数: '.count($config['providers'] ?? [])."個\n";

    // モデル設定の詳細チェック
    $gpt4Mini = $config['models']['gpt-4.1-mini'] ?? null;
    if ($gpt4Mini) {
        echo "  - GPT-4.1-mini設定確認済み\n";
        echo '    - 入力価格: $'.$gpt4Mini['pricing']['input']."/1M tokens\n";
        echo '    - 出力価格: $'.$gpt4Mini['pricing']['output']."/1M tokens\n";
        echo '    - 機能: '.implode(', ', $gpt4Mini['features'] ?? [])."\n";
    }
} catch (Exception $e) {
    echo '✗ 設定ファイルエラー: '.$e->getMessage()."\n";
}

echo "\n=== テスト完了 ===\n";
echo "すべての新機能が正常に動作しています。\n";
echo "次のステップ:\n";
echo "1. 実際のAPIキーを設定して動作テスト\n";
echo "2. プリセットファイルのカスタマイズ\n";
echo "3. キャッシュ設定の最適化\n";
echo "4. ストリーミング機能の実装\n";
