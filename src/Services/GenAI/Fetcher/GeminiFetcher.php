<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Gemini API用のモデル情報Fetcher
 */
class GeminiFetcher extends AbstractFetcher
{
    public function getProviderName(): string
    {
        return 'gemini';
    }

    public function fetchModels(): Collection
    {
        $queryParams = $this->prepareQueryParams();
        $response = $this->makeRequest($this->config['models_endpoint'], $queryParams);
        return $this->parseModelsResponse($response->json());
    }

    public function fetchModel(string $modelId): ?ModelInfo
    {
        try {
            $queryParams = $this->prepareQueryParams();
            $endpoint = $this->config['models_endpoint'] . '/' . $modelId;
            $response = $this->makeRequest($endpoint, $queryParams);
            return $this->parseModelResponse($response->json());
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseModelsResponse(array $data): Collection
    {
        if (!isset($data['models']) || !is_array($data['models'])) {
            return collect();
        }

        return collect($data['models'])->map(function ($model) {
            return $this->parseModelData($model);
        })->filter();
    }

    protected function parseModelResponse(array $data): ModelInfo
    {
        return $this->parseModelData($data);
    }

    /**
     * Gemini APIのモデルデータをModelInfoに変換
     */
    private function parseModelData(array $model): ModelInfo
    {
        $modelId = $this->extractModelId($model['name'] ?? '');

        return new ModelInfo(
            id: $modelId,
            name: $model['displayName'] ?? $modelId,
            provider: 'gemini',
            type: $this->determineModelType($modelId),
            features: $this->inferFeatures($modelId, $model),
            maxTokens: $model['outputTokenLimit'] ?? $this->inferMaxTokens($modelId),
            contextWindow: $model['inputTokenLimit'] ?? $this->inferContextWindow($modelId),
            description: $model['description'] ?? $this->generateDescription($modelId),
            createdAt: null, // Gemini APIは作成日を提供しない
            supportedMethods: $model['supportedGenerationMethods'] ?? ['generateContent'],
            baseModelId: $model['baseModelId'] ?? $this->extractBaseModelId($modelId),
            version: $model['version'] ?? $this->extractVersion($modelId),
        );
    }

    /**
     * モデル名からIDを抽出 (models/gemini-2.0-flash → gemini-2.0-flash)
     */
    private function extractModelId(string $name): string
    {
        if (str_starts_with($name, 'models/')) {
            return substr($name, 7);
        }
        return $name;
    }

    /**
     * モデルIDからタイプを推定
     */
    private function determineModelType(string $modelId): string
    {
        if (str_contains($modelId, 'vision') || str_contains($modelId, 'image')) {
            return 'vision';
        }
        return 'text';
    }

    /**
     * モデルIDと詳細情報から機能を推定
     */
    private function inferFeatures(string $modelId, array $model): array
    {
        $features = ['streaming'];

        // Geminiの基本機能
        $features[] = 'function_calling';
        $features[] = 'structured_output';

        // 最新モデルの特殊機能
        if (str_contains($modelId, '2.5') || str_contains($modelId, '2.0')) {
            $features[] = 'vision';
            $features[] = 'grounding';
        }

        if (str_contains($modelId, 'pro')) {
            $features[] = 'reasoning';
        }

        // supportedGenerationMethodsから機能を推定
        $supportedMethods = $model['supportedGenerationMethods'] ?? [];
        if (in_array('generateContent', $supportedMethods)) {
            $features[] = 'content_generation';
        }

        return array_unique($features);
    }

    /**
     * モデルIDから最大トークン数を推定
     */
    private function inferMaxTokens(string $modelId): ?int
    {
        if (str_contains($modelId, 'pro')) {
            return 8192;
        }
        if (str_contains($modelId, 'flash')) {
            return 8192;
        }
        return 8192; // Geminiのデフォルト
    }

    /**
     * モデルIDからコンテキストウィンドウサイズを推定
     */
    private function inferContextWindow(string $modelId): ?int
    {
        if (str_contains($modelId, '2.5') || str_contains($modelId, '2.0')) {
            return 1000000; // 1M tokens
        }
        if (str_contains($modelId, '1.5')) {
            return 1000000; // 1M tokens
        }
        return 32000; // 旧モデルのデフォルト
    }

    /**
     * モデルの説明を生成
     */
    private function generateDescription(string $modelId): string
    {
        if (str_contains($modelId, '2.5-pro')) {
            return 'Most capable Gemini model with advanced reasoning';
        }
        if (str_contains($modelId, '2.5-flash')) {
            return 'Fast Gemini model optimized for speed and efficiency';
        }
        if (str_contains($modelId, '2.0-flash')) {
            return 'Gemini 2.0 Flash model with multimodal capabilities';
        }
        if (str_contains($modelId, 'pro')) {
            return 'Professional-grade Gemini model';
        }
        if (str_contains($modelId, 'flash')) {
            return 'Fast Gemini model';
        }
        return 'Google Gemini language model';
    }

    /**
     * ベースモデルIDを抽出
     */
    private function extractBaseModelId(string $modelId): ?string
    {
        // 日付やバージョン部分を除去
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
        if (preg_match('/(\d+\.\d+)/', $modelId, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
