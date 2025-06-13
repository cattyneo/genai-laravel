<?php

require_once __DIR__.'/vendor/autoload.php';

use CattyNeo\LaravelGenAI\Facades\GenAI;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== GenAI プロバイダー動作確認テスト ===\n\n";

// テスト用のシンプルなプロンプト
$testPrompt = "Hello! Please respond with 'Provider test successful' in Japanese.";

// 1. OpenAI テスト
echo "1. OpenAI テスト:\n";
try {
    $startTime = microtime(true);
    $response = GenAI::provider('openai')
        ->model('gpt-4.1-mini')
        ->prompt($testPrompt)
        ->request();
    $time = microtime(true) - $startTime;

    echo "- ステータス: 成功\n";
    echo '- レスポンス時間: '.number_format($time * 1000, 2)."ms\n";
    echo '- レスポンス: '.substr($response->content, 0, 100)."...\n";
    echo '- トークン使用量: '.($response->usage['total_tokens'] ?? 'N/A')."\n";
    echo '- コスト: $'.number_format($response->cost, 4)."\n";
} catch (Exception $e) {
    echo "- ステータス: エラー\n";
    echo '- エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 2. Gemini テスト
echo "2. Gemini テスト:\n";
try {
    $startTime = microtime(true);
    $response = GenAI::provider('gemini')
        ->model('gemini-2.0-flash-exp')
        ->prompt($testPrompt)
        ->request();
    $time = microtime(true) - $startTime;

    echo "- ステータス: 成功\n";
    echo '- レスポンス時間: '.number_format($time * 1000, 2)."ms\n";
    echo '- レスポンス: '.substr($response->content, 0, 100)."...\n";
    echo '- トークン使用量: '.($response->usage['total_tokens'] ?? 'N/A')."\n";
    echo '- コスト: $'.number_format($response->cost, 4)."\n";
} catch (Exception $e) {
    echo "- ステータス: エラー\n";
    echo '- エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 3. Claude テスト
echo "3. Claude テスト:\n";
try {
    $startTime = microtime(true);
    $response = GenAI::provider('claude')
        ->model('claude-3-5-sonnet-20241022')
        ->prompt($testPrompt)
        ->request();
    $time = microtime(true) - $startTime;

    echo "- ステータス: 成功\n";
    echo '- レスポンス時間: '.number_format($time * 1000, 2)."ms\n";
    echo '- レスポンス: '.substr($response->content, 0, 100)."...\n";
    echo '- トークン使用量: '.($response->usage['total_tokens'] ?? 'N/A')."\n";
    echo '- コスト: $'.number_format($response->cost, 4)."\n";
} catch (Exception $e) {
    echo "- ステータス: エラー\n";
    echo '- エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 4. Grok テスト
echo "4. Grok テスト:\n";
try {
    $startTime = microtime(true);
    $response = GenAI::provider('grok')
        ->model('grok-3')
        ->prompt($testPrompt)
        ->request();
    $time = microtime(true) - $startTime;

    echo "- ステータス: 成功\n";
    echo '- レスポンス時間: '.number_format($time * 1000, 2)."ms\n";
    echo '- レスポンス: '.substr($response->content, 0, 100)."...\n";
    echo '- トークン使用量: '.($response->usage['total_tokens'] ?? 'N/A')."\n";
    echo '- コスト: $'.number_format($response->cost, 4)."\n";
} catch (Exception $e) {
    echo "- ステータス: エラー\n";
    echo '- エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 5. プリセットテスト
echo "5. プリセットテスト:\n";
$presets = ['default', 'ask', 'create'];

foreach ($presets as $preset) {
    echo "- プリセット '{$preset}':\n";
    try {
        $response = GenAI::preset($preset)
            ->prompt('簡単な挨拶をしてください')
            ->request();

        echo '  ✓ 成功: '.substr($response->content, 0, 50)."...\n";
    } catch (Exception $e) {
        echo '  ✗ エラー: '.$e->getMessage()."\n";
    }
}

echo "\n";

// 6. 異なるオプションテスト
echo "6. 異なるオプションテスト:\n";

// 低温度設定
echo "- 低温度 (temperature=0.1):\n";
try {
    $response = GenAI::temperature(0.1)
        ->prompt('数字の1から5を順番に書いてください')
        ->request();
    echo '  レスポンス: '.substr($response->content, 0, 50)."...\n";
} catch (Exception $e) {
    echo '  エラー: '.$e->getMessage()."\n";
}

// 高温度設定
echo "- 高温度 (temperature=0.9):\n";
try {
    $response = GenAI::temperature(0.9)
        ->prompt('数字の1から5を順番に書いてください')
        ->request();
    echo '  レスポンス: '.substr($response->content, 0, 50)."...\n";
} catch (Exception $e) {
    echo '  エラー: '.$e->getMessage()."\n";
}

// 最大トークン制限
echo "- 最大トークン制限 (max_tokens=50):\n";
try {
    $response = GenAI::maxTokens(50)
        ->prompt('人工知能について詳しく説明してください')
        ->request();
    echo '  レスポンス: '.substr($response->content, 0, 100)."...\n";
    echo '  トークン数: '.($response->usage['total_tokens'] ?? 'N/A')."\n";
} catch (Exception $e) {
    echo '  エラー: '.$e->getMessage()."\n";
}

echo "\n=== プロバイダーテスト完了 ===\n";
