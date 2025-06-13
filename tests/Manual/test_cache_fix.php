<?php

require_once __DIR__ . '/vendor/autoload.php';

// 必要な環境変数をロード
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
        putenv(trim($name) . '=' . trim($value));
    }
}

// Laravel アプリケーションの設定
$_ENV['APP_NAME'] = $_ENV['APP_NAME'] ?? 'Laravel';
$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'local';
$_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? 'true';

use CattyNeo\LaravelGenAI\Facades\GenAI;

echo "Testing GenAI with cache disabled...\n";

try {
    $response = GenAI::ask("こんにちは！");
    echo "Success! Response: " . $response . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}