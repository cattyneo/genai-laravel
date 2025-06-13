<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Illuminate\Support\Collection;

/**
 * Claude API用のモデル情報Fetcher
 */
class ClaudeFetcher extends AbstractFetcher
{
    public function getProviderName(): string
    {
        return 'claude';
    }

    public function fetchModels(): Collection
    {
        $response = $this->makeRequest($this->config['models_endpoint']);

        return $this->parseModelsResponse($response->json());
    }

    public function fetchModel(string $modelId): ?ModelInfo
    {
        try {
            $endpoint = $this->config['models_endpoint'].'/'.$modelId;
            $response = $this->makeRequest($endpoint);

            return $this->parseModelResponse($response->json());
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseModelsResponse(array $data): Collection
    {
        if (! isset($data['data']) || ! is_array($data['data'])) {
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
     * Claude APIのモデルデータをModelInfoに変換
     */
    private function parseModelData(array $model): ModelInfo
    {
        $modelId = $model['id'];

        return new ModelInfo(
            id: $modelId,
            name: $model['display_name'] ?? $modelId,
            provider: 'claude',
            type: $this->determineModelType($modelId),
            features: $this->inferFeatures($modelId),
            maxTokens: $this->inferMaxTokens($modelId),
            contextWindow: $this->inferContextWindow($modelId),
            description: $this->generateDescription($modelId),
            createdAt: isset($model['created_at']) ? Carbon::parse($model['created_at']) : null,
            supportedMethods: ['messages'],
            baseModelId: $this->extractBaseModelId($modelId),
            version: $this->extractVersion($modelId),
        );
    }

    /**
     * モデルIDからタイプを推定
     */
    private function determineModelType(string $modelId): string
    {
        // Claudeは主にテキストモデル（一部でvision機能）
        return 'text';
    }

    /**
     * モデルIDから機能を推定
     */
    private function inferFeatures(string $modelId): array
    {
        $features = ['streaming'];

        // Claude 4の新機能
        if (str_contains($modelId, 'claude-4') || str_contains($modelId, 'sonnet-4') || str_contains($modelId, 'opus-4')) {
            $features[] = 'vision';
            $features[] = 'function_calling';
            $features[] = 'structured_output';
            $features[] = 'reasoning';
        }
        // Claude 3.5の機能
        elseif (str_contains($modelId, '3-5') || str_contains($modelId, '3.5')) {
            $features[] = 'vision';
            $features[] = 'function_calling';
            $features[] = 'structured_output';
        }
        // Claude 3の基本機能
        elseif (str_contains($modelId, 'claude-3')) {
            $features[] = 'vision';
            $features[] = 'function_calling';
        }

        // Sonnet/Opusは高性能機能
        if (str_contains($modelId, 'sonnet') || str_contains($modelId, 'opus')) {
            $features[] = 'advanced_reasoning';
        }

        // Haikuは軽量・高速
        if (str_contains($modelId, 'haiku')) {
            $features[] = 'fast_response';
        }

        return array_unique($features);
    }

    /**
     * モデルIDから最大トークン数を推定
     */
    private function inferMaxTokens(string $modelId): ?int
    {
        if (str_contains($modelId, 'sonnet-4')) {
            return 64000;
        }
        if (str_contains($modelId, 'opus-4')) {
            return 32000;
        }
        if (str_contains($modelId, '3-5') || str_contains($modelId, '3.5')) {
            return 8192;
        }
        if (str_contains($modelId, 'claude-3')) {
            if (str_contains($modelId, 'opus')) {
                return 4096;
            }

            return 4096;
        }

        return 4096; // デフォルト
    }

    /**
     * モデルIDからコンテキストウィンドウサイズを推定
     */
    private function inferContextWindow(string $modelId): ?int
    {
        if (str_contains($modelId, 'claude-4') || str_contains($modelId, 'sonnet-4') || str_contains($modelId, 'opus-4')) {
            return 200000; // 200K tokens
        }
        if (str_contains($modelId, '3-5') || str_contains($modelId, '3.5')) {
            return 200000; // 200K tokens
        }
        if (str_contains($modelId, 'claude-3')) {
            return 200000; // 200K tokens
        }

        return 200000; // デフォルト
    }

    /**
     * モデルの説明を生成
     */
    private function generateDescription(string $modelId): string
    {
        if (str_contains($modelId, 'opus-4')) {
            return 'Most capable Claude 4 model with superior reasoning capabilities';
        }
        if (str_contains($modelId, 'sonnet-4')) {
            return 'High-performance Claude 4 model with exceptional reasoning and efficiency';
        }
        if (str_contains($modelId, 'sonnet-3-7')) {
            return 'High-performance Claude model with early extended thinking';
        }
        if (str_contains($modelId, 'sonnet-3-5')) {
            return 'Intelligent Claude model with high capability';
        }
        if (str_contains($modelId, 'haiku-3-5')) {
            return 'Fast Claude model optimized for speed';
        }
        if (str_contains($modelId, 'opus-3')) {
            return 'Most capable Claude 3 model for complex tasks';
        }
        if (str_contains($modelId, 'sonnet-3')) {
            return 'Balanced Claude 3 model for general use';
        }
        if (str_contains($modelId, 'haiku-3')) {
            return 'Fast and compact Claude 3 model';
        }

        return 'Anthropic Claude language model';
    }

    /**
     * ベースモデルIDを抽出
     */
    private function extractBaseModelId(string $modelId): ?string
    {
        // 日付部分を除去してベースモデルIDを取得
        return preg_replace('/-\d{8}$/', '', $modelId);
    }

    /**
     * バージョンを抽出
     */
    private function extractVersion(string $modelId): ?string
    {
        if (preg_match('/-(\d{8})$/', $modelId, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
