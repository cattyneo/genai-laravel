<?php

require_once __DIR__ . '/vendor/autoload.php';

// Laravel アプリケーションを起動
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\GenAIRequest;
use App\Models\GenAIStat;

echo "=== データベース履歴確認 ===\n\n";

echo "GenAI Requests: " . GenAIRequest::count() . "\n";
echo "GenAI Stats: " . GenAIStat::count() . "\n\n";

echo "最新のリクエスト履歴:\n";
GenAIRequest::latest()->take(5)->get(['provider', 'model', 'prompt', 'response_content', 'status', 'duration_ms'])
    ->each(function($request) {
        echo "- {$request->provider}/{$request->model}: {$request->prompt}\n";
        echo "  -> {$request->response_content} ({$request->status}, {$request->duration_ms}ms)\n\n";
    });

echo "統計情報:\n";
GenAIStat::all(['date', 'provider', 'model', 'total_requests', 'successful_requests', 'total_tokens'])
    ->each(function($stat) {
        echo "- {$stat->date} {$stat->provider}/{$stat->model}: {$stat->total_requests} requests, {$stat->successful_requests} success, {$stat->total_tokens} tokens\n";
    });

echo "\n=== 確認完了 ===\n";