<?php

require_once __DIR__.'/vendor/autoload.php';

// Laravel アプリケーションを起動
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\GenAI\GenAIManager;

echo "=== チェーンメソッドのテスト ===\n\n";

$genai = app(GenAIManager::class);

// 1. シンプルなチェーンメソッド
echo "1. シンプルなチェーンメソッド:\n";
try {
    $response = $genai
        ->prompt('こんにちは！')
        ->temperature(0.5)
        ->maxTokens(50)
        ->request();

    echo '✅ 成功: '.$response->content."\n";
} catch (Exception $e) {
    echo '❌ エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 2. システムプロンプト付き
echo "2. システムプロンプト付き:\n";
try {
    $response = $genai
        ->systemPrompt('あなたは親切なアシスタントです。')
        ->prompt('今日の天気について教えて')
        ->temperature(0.7)
        ->request();

    echo '✅ 成功: '.$response->content."\n";
} catch (Exception $e) {
    echo '❌ エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 3. 複数のオプション設定
echo "3. 複数のオプション設定:\n";
try {
    $response = $genai
        ->prompt('短い詩を書いて')
        ->options([
            'temperature' => 0.9,
            'max_tokens' => 100,
            'top_p' => 0.9,
        ])
        ->request();

    echo '✅ 成功: '.$response->content."\n";
} catch (Exception $e) {
    echo '❌ エラー: '.$e->getMessage()."\n";
}

echo "\n";

// 4. 従来のask()メソッド
echo "4. 従来のask()メソッド:\n";
try {
    $response = $genai->ask('簡単な挨拶をして');
    echo '✅ 成功: '.$response."\n";
} catch (Exception $e) {
    echo '❌ エラー: '.$e->getMessage()."\n";
}

echo "\n=== テスト完了 ===\n";
