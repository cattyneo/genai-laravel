<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Models\GenAIStat;
use Illuminate\Support\Str;

final class RequestLogger
{
    public function __construct(
        private bool $enabled = true
    ) {}

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

        $genaiRequest = GenAIRequest::create([
            'provider' => $provider,
            'model' => $model,
            'prompt' => $request->prompt,
            'system_prompt' => $request->systemPrompt,
            'options' => $request->options,
            'vars' => $request->vars,
            'response' => $response->content,
            'response_usage' => $response->usage,
            'cost' => $response->cost,
            'response_meta' => $response->meta,
            'status' => $error ? 'error' : 'success',
            'error_message' => $error,
            'input_tokens' => $response->usage['input_tokens'] ?? $response->usage['prompt_tokens'] ?? 0,
            'output_tokens' => $response->usage['output_tokens'] ?? $response->usage['completion_tokens'] ?? 0,
            'total_tokens' => $response->usage['total_tokens'] ?? 0,
            'duration_ms' => $durationMs,
            'user_id' => auth()->id() ?? session()->getId(),
            'session_id' => session()->getId(),
            'request_id' => (string) Str::uuid(),
        ]);

        // 統計を更新
        $this->updateStats(
            $provider,
            $model,
            $genaiRequest,
            now()->toDateString()
        );

        return $genaiRequest;
    }

    private function updateStats(
        string $provider,
        string $model,
        GenAIRequest $request,
        string $date
    ): void {
        try {
            \DB::transaction(function () use ($provider, $model, $request, $date) {
                $stat = GenAIStat::where([
                    'date' => $date,
                    'provider' => $provider,
                    'model' => $model,
                ])->lockForUpdate()->first();

                if (! $stat) {
                    GenAIStat::create([
                        'date' => $date,
                        'provider' => $provider,
                        'model' => $model,
                        'total_requests' => 1,
                        'successful_requests' => $request->status === 'success' ? 1 : 0,
                        'failed_requests' => $request->status === 'error' ? 1 : 0,
                        'total_input_tokens' => $request->input_tokens,
                        'total_output_tokens' => $request->output_tokens,
                        'total_tokens' => $request->total_tokens,
                        'total_cost' => $request->cost,
                        'avg_duration_ms' => $request->duration_ms,
                    ]);
                } else {
                    $stat->increment('total_requests');

                    if ($request->status === 'success') {
                        $stat->increment('successful_requests');
                    } else {
                        $stat->increment('failed_requests');
                    }

                    $stat->increment('total_input_tokens', $request->input_tokens);
                    $stat->increment('total_output_tokens', $request->output_tokens);
                    $stat->increment('total_tokens', $request->total_tokens);
                    $stat->increment('total_cost', $request->cost);

                    // 平均時間を再計算
                    $totalDuration = GenAIRequest::byProvider($provider)
                        ->byModel($model)
                        ->whereDate('created_at', $date)
                        ->avg('duration_ms');

                    $stat->update(['avg_duration_ms' => $totalDuration ?? 0]);
                }
            });
        } catch (\Exception $e) {
            // 統計更新エラーは無視（ログ記録は成功させる）
            \Log::warning('Failed to update GenAI stats', [
                'provider' => $provider,
                'model' => $model,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
