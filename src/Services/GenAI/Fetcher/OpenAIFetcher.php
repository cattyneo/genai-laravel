<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Illuminate\Support\Collection;

/**
 * OpenAI API用のモデル情報Fetcher
 */
class OpenAIFetcher extends AbstractFetcher
{
    public function getProviderName(): string
    {
        return 'openai';
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
     * OpenAI APIのモデルデータをModelInfoに変換
     */
    private function parseModelData(array $model): ModelInfo
    {
        return new ModelInfo(
            id: $model['id'],
            name: $model['id'],
            provider: 'openai',
            type: $this->determineModelType($model['id']),
            features: $model['features'] ?? $this->inferFeatures($model['id']),
            maxTokens: $model['max_tokens'] ?? $this->inferMaxTokens($model['id']),
            contextWindow: $this->inferContextWindow($model['id']),
            description: $this->generateDescription($model['id']),
            createdAt: isset($model['created']) ? Carbon::createFromTimestamp($model['created']) : null,
            supportedMethods: $model['supported_methods'] ?? ['chat.completions'],
            baseModelId: $this->extractBaseModelId($model['id']),
            version: $this->extractVersion($model['id']),
        );
    }

    /**
     * モデルIDからタイプを推定
     */
    private function determineModelType(string $modelId): string
    {
        if (str_contains($modelId, 'dall-e') || str_contains($modelId, 'image')) {
            return 'image';
        }
        if (str_contains($modelId, 'whisper') || str_contains($modelId, 'tts')) {
            return 'audio';
        }
        if (str_contains($modelId, 'embedding')) {
            return 'embedding';
        }

        return 'text';
    }

    /**
     * モデルIDから機能を推定
     */
    private function inferFeatures(string $modelId): array
    {
        $features = ['streaming'];

        if (str_contains($modelId, 'gpt-4') || str_contains($modelId, 'gpt-3.5')) {
            $features[] = 'function_calling';
            $features[] = 'system_message';
        }

        if (str_contains($modelId, 'gpt-4o') || str_contains($modelId, 'gpt-4.1')) {
            $features[] = 'vision';
            $features[] = 'structured_output';
        }

        if (str_contains($modelId, 'o1') || str_contains($modelId, 'o3') || str_contains($modelId, 'o4')) {
            $features[] = 'reasoning';
        }

        if (str_contains($modelId, 'dall-e')) {
            $features = ['image_generation'];
        }

        if (str_contains($modelId, 'whisper')) {
            $features = ['transcription'];
        }

        if (str_contains($modelId, 'tts')) {
            $features = ['text_to_speech'];
        }

        return $features;
    }

    /**
     * モデルIDから最大トークン数を推定
     */
    private function inferMaxTokens(string $modelId): ?int
    {
        if (str_contains($modelId, 'gpt-4.1')) {
            return 16384;
        }
        if (str_contains($modelId, 'gpt-4o')) {
            return 16384;
        }
        if (str_contains($modelId, 'o3')) {
            return 100000;
        }
        if (str_contains($modelId, 'o4-mini')) {
            return 65536;
        }
        if (str_contains($modelId, 'gpt-4')) {
            return 8192;
        }
        if (str_contains($modelId, 'gpt-3.5')) {
            return 4096;
        }

        return null;
    }

    /**
     * モデルIDからコンテキストウィンドウサイズを推定
     */
    private function inferContextWindow(string $modelId): ?int
    {
        if (str_contains($modelId, 'gpt-4.1') || str_contains($modelId, 'gpt-4o')) {
            return 1000000; // 1M tokens
        }
        if (str_contains($modelId, 'o3')) {
            return 200000;
        }
        if (str_contains($modelId, 'o4-mini')) {
            return 128000;
        }
        if (str_contains($modelId, 'gpt-4')) {
            return 128000;
        }
        if (str_contains($modelId, 'gpt-3.5-turbo-16k')) {
            return 16384;
        }
        if (str_contains($modelId, 'gpt-3.5')) {
            return 4096;
        }

        return null;
    }

    /**
     * モデルの説明を生成
     */
    private function generateDescription(string $modelId): string
    {
        if (str_contains($modelId, 'gpt-4.1')) {
            return 'Latest GPT-4.1 model with enhanced capabilities';
        }
        if (str_contains($modelId, 'gpt-4o')) {
            return 'GPT-4 Omni model with multimodal capabilities';
        }
        if (str_contains($modelId, 'o3')) {
            return 'Advanced reasoning model';
        }
        if (str_contains($modelId, 'dall-e-3')) {
            return 'Latest image generation model';
        }
        if (str_contains($modelId, 'whisper')) {
            return 'Speech-to-text model';
        }
        if (str_contains($modelId, 'tts')) {
            return 'Text-to-speech model';
        }

        return 'OpenAI language model';
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

        return null;
    }
}
