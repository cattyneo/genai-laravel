<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Models\GenAIStat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 最適化されたデータベースロガー
 */
final class DatabaseLogger
{
    private array $pendingStats = [];

    private int $batchSize;

    private bool $deferStatsUpdate;

    public function __construct(
        private bool $enabled = true,
        int $batchSize = 10,
        bool $deferStatsUpdate = true
    ) {
        $this->batchSize = $batchSize;
        $this->deferStatsUpdate = $deferStatsUpdate;
    }

    /**
     * リクエストをログに記録
     */
    public function logRequest(
        GenAIRequestData $request,
        GenAIResponseData $response,
        string $provider,
        string $model,
        float $durationMs,
        ?string $error = null
    ): ?GenAIRequest {
        if (! $this->enabled) {
            return null;
        }

        $genaiRequest = $this->createRequestRecord(
            $request,
            $response,
            $provider,
            $model,
            $durationMs,
            $error
        );

        // 統計更新の処理方法を選択
        if ($this->deferStatsUpdate) {
            $this->addToPendingStats($provider, $model, $genaiRequest);
        } else {
            $this->updateStatsImmediate($provider, $model, $genaiRequest);
        }

        return $genaiRequest;
    }

    /**
     * リクエストレコードを作成
     */
    private function createRequestRecord(
        GenAIRequestData $request,
        GenAIResponseData $response,
        string $provider,
        string $model,
        float $durationMs,
        ?string $error
    ): GenAIRequest {
        return GenAIRequest::create([
            'provider' => $provider,
            'model' => $model,
            'prompt' => $this->truncatePrompt($request->prompt),
            'system_prompt' => $request->systemPrompt,
            'options' => $request->options,
            'vars' => $request->vars,
            'response' => $this->truncateResponse($response->content),
            'response_usage' => $response->usage,
            'cost' => $response->cost,
            'response_meta' => $this->sanitizeResponseMeta($response->meta),
            'status' => $error ? 'error' : 'success',
            'error_message' => $error,
            'input_tokens' => $response->usage['input_tokens'] ?? $response->usage['prompt_tokens'] ?? 0,
            'output_tokens' => $response->usage['output_tokens'] ?? $response->usage['completion_tokens'] ?? 0,
            'total_tokens' => $response->usage['total_tokens'] ?? 0,
            'duration_ms' => $durationMs,
            'user_id' => Auth::id(),
            'session_id' => session()->getId(),
            'request_id' => (string) Str::orderedUuid(),
        ]);
    }

    /**
     * 統計を即座に更新
     */
    private function updateStatsImmediate(
        string $provider,
        string $model,
        GenAIRequest $request
    ): void {
        $this->addToPendingStats($provider, $model, $request);
        $this->flushPendingStats();
    }

    /**
     * 保留中の統計に追加
     */
    private function addToPendingStats(
        string $provider,
        string $model,
        GenAIRequest $request
    ): void {
        $date = now()->toDateString();
        $key = "{$provider}:{$model}:{$date}";

        if (! isset($this->pendingStats[$key])) {
            $this->pendingStats[$key] = [
                'provider' => $provider,
                'model' => $model,
                'date' => $date,
                'requests' => [],
            ];
        }

        $this->pendingStats[$key]['requests'][] = $request;

        // バッチサイズに達したら処理
        if (count($this->pendingStats[$key]['requests']) >= $this->batchSize) {
            $this->processStatsBatch($key);
        }
    }

    /**
     * 保留中の統計をすべて処理
     */
    public function flushPendingStats(): void
    {
        foreach (array_keys($this->pendingStats) as $key) {
            $this->processStatsBatch($key);
        }
    }

    /**
     * 統計バッチを処理
     */
    private function processStatsBatch(string $key): void
    {
        if (! isset($this->pendingStats[$key]) || empty($this->pendingStats[$key]['requests'])) {
            return;
        }

        $batch = $this->pendingStats[$key];
        unset($this->pendingStats[$key]);

        try {
            DB::transaction(function () use ($batch) {
                $this->updateStatsBatch($batch);
            }, 3); // 3回リトライ
        } catch (\Exception $e) {
            Log::warning('Failed to update GenAI stats batch', [
                'provider' => $batch['provider'],
                'model' => $batch['model'],
                'date' => $batch['date'],
                'batch_size' => count($batch['requests']),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * バッチ統計を更新
     */
    private function updateStatsBatch(array $batch): void
    {
        $requests = $batch['requests'];
        $totalRequests = count($requests);

        $successfulRequests = collect($requests)->where('status', 'success')->count();
        $failedRequests = $totalRequests - $successfulRequests;

        $totalInputTokens = collect($requests)->sum('input_tokens');
        $totalOutputTokens = collect($requests)->sum('output_tokens');
        $totalTokens = collect($requests)->sum('total_tokens');
        $totalCost = collect($requests)->sum('cost');
        $avgDuration = collect($requests)->avg('duration_ms') ?? 0;

        GenAIStat::updateOrCreate(
            [
                'date' => $batch['date'],
                'provider' => $batch['provider'],
                'model' => $batch['model'],
            ],
            [
                'total_requests' => DB::raw("total_requests + {$totalRequests}"),
                'successful_requests' => DB::raw("successful_requests + {$successfulRequests}"),
                'failed_requests' => DB::raw("failed_requests + {$failedRequests}"),
                'total_input_tokens' => DB::raw("total_input_tokens + {$totalInputTokens}"),
                'total_output_tokens' => DB::raw("total_output_tokens + {$totalOutputTokens}"),
                'total_tokens' => DB::raw("total_tokens + {$totalTokens}"),
                'total_cost' => DB::raw("total_cost + {$totalCost}"),
                'avg_duration_ms' => $avgDuration,
            ]
        );
    }

    /**
     * プロンプトを適切な長さに切り詰め
     */
    private function truncatePrompt(string $prompt): string
    {
        return Str::limit($prompt, 5000);
    }

    /**
     * レスポンスを適切な長さに切り詰め
     */
    private function truncateResponse(string $response): string
    {
        return Str::limit($response, 10000);
    }

    /**
     * レスポンスメタデータをサニタイズ
     */
    private function sanitizeResponseMeta(array $meta): array
    {
        // 巨大なメタデータを除去
        $allowedKeys = ['id', 'object', 'created', 'model', 'usage', 'system_fingerprint'];

        return array_intersect_key($meta, array_flip($allowedKeys));
    }

    /**
     * デストラクタで残りの統計を処理
     */
    public function __destruct()
    {
        if (! empty($this->pendingStats)) {
            $this->flushPendingStats();
        }
    }
}
