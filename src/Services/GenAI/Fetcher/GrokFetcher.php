<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Grok API用のモデル情報Fetcher
 */
class GrokFetcher extends AbstractFetcher
{
    public function getProviderName(): string
    {
        return 'grok';
    }

    public function fetchModels(): Collection
    {
        $response = $this->makeRequest($this->config['models_endpoint']);
        return $this->parseModelsResponse($response->json());
    }

    public function fetchModel(string $modelId): ?ModelInfo
    {
        try {
            $endpoint = $this->config['models_endpoint'] . '/' . $modelId;
            $response = $this->makeRequest($endpoint);
            return $this->parseModelResponse($response->json());
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseModelsResponse(array $data): Collection
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return collect();
        }

        return collect($data['data'])->map(function ($model) {
            return $this->parseModelData($model);
        })->filter();
    }

    protected function parseModelResponse(array $data): ModelInfo
    {
        return $this->parseModelData($data);
    }

    /**
     * Grok APIのモデルデータをModelInfoに変換
     */
    private function parseModelData(array $model): ModelInfo
    {
        $modelId = $model['id'];

        return new ModelInfo(
            id: $modelId,
            name: $modelId,
            provider: 'grok',
            type: $this->determineModelType($modelId),
            features: $this->inferFeatures($modelId),
            maxTokens: $this->inferMaxTokens($modelId),
            contextWindow: $this->inferContextWindow($modelId),
            description: $this->generateDescription($modelId),
            createdAt: isset($model['created']) ? Carbon::createFromTimestamp($model['created']) : null,
            supportedMethods: ['chat.completions'],
            baseModelId: $this->extractBaseModelId($modelId),
            version: $this->extractVersion($modelId),
        );
    }

    /**
     * モデルIDからタイプを推定
     */
    private function determineModelType(string $modelId): string
    {
        if (str_contains($modelId, 'vision')) {
            return 'vision';
        }
        return 'text';
    }

    /**
     * モデルIDから機能を推定
     */
    private function inferFeatures(string $modelId): array
    {
        $features = ['streaming'];

        // Grok 3以降の機能
        if (str_contains($modelId, 'grok-3') || str_contains($modelId, 'grok-beta')) {
            $features[] = 'function_calling';
        }

        // 推理機能
        if (str_contains($modelId, 'grok-3') && !str_contains($modelId, 'fast')) {
            $features[] = 'reasoning';
        }

        // ベータ版の特殊機能
        if (str_contains($modelId, 'beta')) {
            $features[] = 'real_time_data';
            $features[] = 'x_platform_integration';
        }

        // Vision機能
        if (str_contains($modelId, 'vision')) {
            $features[] = 'vision';
        }

        return array_unique($features);
    }

    /**
     * モデルIDから最大トークン数を推定
     */
    private function inferMaxTokens(string $modelId): ?int
    {
        if (str_contains($modelId, 'grok-3') || str_contains($modelId, 'grok-beta')) {
            return 131072; // 131K tokens
        }
        return 131072; // デフォルト
    }

    /**
     * モデルIDからコンテキストウィンドウサイズを推定
     */
    private function inferContextWindow(string $modelId): ?int
    {
        if (str_contains($modelId, 'grok-3') && !str_contains($modelId, 'fast') && !str_contains($modelId, 'mini')) {
            return 1000000; // 1M tokens (announced but API limited)
        }
        if (str_contains($modelId, 'grok-beta')) {
            return 131072; // 131K tokens
        }
        return 131072; // デフォルト
    }

    /**
     * モデルの説明を生成
     */
    private function generateDescription(string $modelId): string
    {
        if (str_contains($modelId, 'grok-beta')) {
            return 'Grok Beta model with real-time data access and X platform integration';
        }
        if (str_contains($modelId, 'grok-3-mini-fast')) {
            return 'Fastest Grok 3 mini model optimized for speed';
        }
        if (str_contains($modelId, 'grok-3-mini')) {
            return 'Compact Grok 3 model with reasoning capabilities';
        }
        if (str_contains($modelId, 'grok-3-fast')) {
            return 'Fast Grok 3 model optimized for quick responses';
        }
        if (str_contains($modelId, 'grok-3')) {
            return 'Advanced Grok 3 model with reasoning capabilities';
        }
        if (str_contains($modelId, 'grok-2')) {
            return 'Grok 2 model with enhanced capabilities';
        }
        if (str_contains($modelId, 'vision')) {
            return 'Grok model with vision capabilities';
        }
        return 'xAI Grok language model';
    }

    /**
     * ベースモデルIDを抽出
     */
    private function extractBaseModelId(string $modelId): ?string
    {
        // 日付部分を除去してベースモデルIDを取得
        return preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $modelId);
    }

    /**
     * バージョンを抽出
     */
    private function extractVersion(string $modelId): ?string
    {
        if (preg_match('/-(\d{4}-\d{2}-\d{2})$/', $modelId, $matches)) {
            return $matches[1];
        }
        if (str_contains($modelId, 'beta')) {
            return 'beta';
        }
        return null;
    }
}
