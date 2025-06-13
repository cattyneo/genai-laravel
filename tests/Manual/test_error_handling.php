<?php

require_once __DIR__ . '/vendor/autoload.php';

use CattyNeo\LaravelGenAI\Facades\GenAI;
use CattyNeo\LaravelGenAI\Exceptions\GenAIException;
use CattyNeo\LaravelGenAI\Exceptions\ProviderException;
use CattyNeo\LaravelGenAI\Exceptions\RateLimitException;
use CattyNeo\LaravelGenAI\Exceptions\InvalidConfigException;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== GenAI エラーハンドリング詳細テスト ===\n\n";

// 1. 無効なプロバイダーテスト
echo "1. 無効なプロバイダーテスト:\n";
try {
    $response = GenAI::provider('invalid_provider')
        ->prompt("テスト")
        ->request();
    echo "- 予期しない成功\n";
} catch (InvalidConfigException $e) {
    echo "- ✓ InvalidConfigException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "- ✓ 期待通りのエラー: " . get_class($e) . " - " . $e->getMessage() . "\n";
}

echo "\n";

// 2. 無効なモデルテスト
echo "2. 無効なモデルテスト:\n";
$invalidModels = [
    'openai' => 'gpt-invalid',
    'gemini' => 'gemini-invalid',
    'claude' => 'claude-invalid',
    'grok' => 'grok-invalid'
];

foreach ($invalidModels as $provider => $model) {
    echo "- {$provider} - {$model}:\n";
    try {
        $response = GenAI::provider($provider)
            ->model($model)
            ->prompt("テスト")
            ->request();
        echo "  予期しない成功\n";
    } catch (ProviderException $e) {
        echo "  ✓ ProviderException: " . substr($e->getMessage(), 0, 100) . "...\n";
    } catch (Exception $e) {
        echo "  ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
    }
}

echo "\n";

// 3. 無効なAPIキーテスト
echo "3. 無効なAPIキーテスト:\n";
echo "- 注意: 実際のAPIキーを一時的に無効化してテストします\n";

// 元のAPIキーを保存
$originalKeys = [
    'OPENAI_API_KEY' => env('OPENAI_API_KEY'),
    'GEMINI_API_KEY' => env('GEMINI_API_KEY'),
    'CLAUDE_API_KEY' => env('CLAUDE_API_KEY'),
    'GROK_API_KEY' => env('GROK_API_KEY'),
];

// 無効なAPIキーでテスト（実際には設定を変更せず、プロバイダー設定で無効なキーを使用）
$testProviders = ['openai', 'gemini', 'claude', 'grok'];

foreach ($testProviders as $provider) {
    echo "- {$provider}:\n";
    try {
        // 無効なAPIキーを設定したプロバイダーを作成
        $config = config("genai.providers.{$provider}");
        $config['api_key'] = 'invalid_key_' . $provider;

        $providerFactory = app(\CattyNeo\LaravelGenAI\Services\GenAI\ProviderFactory::class);
        $providerInstance = $providerFactory->create($provider, $config);

        $response = $providerInstance->request(
            userPrompt: "テスト",
            systemPrompt: null,
            options: ['temperature' => 0.7, 'max_tokens' => 10],
            model: match ($provider) {
                'openai' => 'gpt-4.1-mini',
                'gemini' => 'gemini-2.0-flash-exp',
                'claude' => 'claude-3-5-sonnet-20241022',
                'grok' => 'grok-3-beta',
            }
        );
        echo "  予期しない成功\n";
    } catch (Exception $e) {
        echo "  ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
    }
}

echo "\n";

// 4. ネットワークエラーシミュレーション
echo "4. ネットワークエラーシミュレーション:\n";
try {
    // 無効なベースURLでテスト
    $config = config('genai.providers.openai');
    $config['base_url'] = 'https://invalid-url-that-does-not-exist.com/v1';

    $providerFactory = app(\CattyNeo\LaravelGenAI\Services\GenAI\ProviderFactory::class);
    $providerInstance = $providerFactory->create('openai', $config);

    $response = $providerInstance->request(
        userPrompt: "テスト",
        systemPrompt: null,
        options: ['temperature' => 0.7, 'max_tokens' => 10],
        model: 'gpt-4.1-mini'
    );
    echo "- 予期しない成功\n";
} catch (Exception $e) {
    echo "- ✓ ネットワークエラー: " . get_class($e) . " - " . substr($e->getMessage(), 0, 100) . "...\n";
}

echo "\n";

// 5. 大きすぎるリクエストテスト
echo "5. 大きすぎるリクエストテスト:\n";
try {
    // 非常に長いプロンプトを作成
    $longPrompt = str_repeat("これは非常に長いプロンプトです。", 1000);

    $response = GenAI::prompt($longPrompt)
        ->maxTokens(1)
        ->request();
    echo "- 予期しない成功\n";
} catch (Exception $e) {
    echo "- ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
}

echo "\n";

// 6. 無効なオプションテスト
echo "6. 無効なオプションテスト:\n";

// 無効な温度設定
echo "- 無効な温度設定 (temperature=5.0):\n";
try {
    $response = GenAI::temperature(5.0)
        ->prompt("テスト")
        ->request();
    echo "  予期しない成功\n";
} catch (Exception $e) {
    echo "  ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
}

// 負の最大トークン数
echo "- 負の最大トークン数 (max_tokens=-100):\n";
try {
    $response = GenAI::maxTokens(-100)
        ->prompt("テスト")
        ->request();
    echo "  予期しない成功\n";
} catch (Exception $e) {
    echo "  ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
}

echo "\n";

// 7. プリセットエラーテスト
echo "7. プリセットエラーテスト:\n";
try {
    $response = GenAI::preset('non_existent_preset')
        ->prompt("テスト")
        ->request();
    echo "- 予期しない成功\n";
} catch (Exception $e) {
    echo "- ✓ " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "\n";

// 8. タイムアウトテスト
echo "8. タイムアウトテスト:\n";
try {
    $response = GenAI::options(['timeout' => 0.001]) // 1ms timeout
        ->prompt("非常に複雑な計算を実行してください。素数を1000個見つけて、それぞれについて詳細な説明を書いてください。")
        ->request();
    echo "- 予期しない成功\n";
} catch (Exception $e) {
    echo "- ✓ " . get_class($e) . ": " . substr($e->getMessage(), 0, 100) . "...\n";
}

echo "\n";

// 9. 例外の階層テスト
echo "9. 例外の階層テスト:\n";
try {
    throw new ProviderException("テスト例外");
} catch (GenAIException $e) {
    echo "- ✓ ProviderExceptionはGenAIExceptionを継承: " . get_class($e) . "\n";
} catch (Exception $e) {
    echo "- ✗ 予期しない例外階層: " . get_class($e) . "\n";
}

echo "\n";

// 10. エラーログ確認
echo "10. エラーログ確認:\n";
try {
    // ログファイルの最新エントリを確認
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath)) {
        $logContent = file_get_contents($logPath);
        $genaiErrors = substr_count($logContent, 'GenAI');
        echo "- ログファイル存在: ✓\n";
        echo "- GenAI関連エラー数: {$genaiErrors}\n";
    } else {
        echo "- ログファイル存在: ✗\n";
    }
} catch (Exception $e) {
    echo "- ログ確認エラー: " . $e->getMessage() . "\n";
}

echo "\n";

// 11. リカバリーテスト
echo "11. リカバリーテスト:\n";
echo "- エラー後の正常リクエスト:\n";
try {
    $response = GenAI::ask("こんにちは");
    echo "  ✓ 正常復旧: " . substr($response, 0, 50) . "...\n";
} catch (Exception $e) {
    echo "  ✗ 復旧失敗: " . $e->getMessage() . "\n";
}

echo "\n=== エラーハンドリングテスト完了 ===\n";
