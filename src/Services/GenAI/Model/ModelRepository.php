<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Model;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use CattyNeo\LaravelGenAI\Exceptions\InvalidConfigException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * YAMLファイルからモデル情報を管理するRepository
 */
class ModelRepository
{
    private string $yamlPath;
    private int $cacheTtl;
    private string $cacheKey;

    public function __construct()
    {
        $this->yamlPath = storage_path('genai/models.yaml');
        $this->cacheTtl = config('genai.cache.ttl', 3600);
        $this->cacheKey = 'genai_models_yaml';
    }

    /**
     * YAMLファイルから全モデル情報を取得
     *
     * @return Collection<ModelInfo>
     */
    public function getAllModels(): Collection
    {
        return Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            return $this->loadModelsFromYaml();
        });
    }

    /**
     * 特定のプロバイダーのモデル一覧を取得
     *
     * @param string $provider
     * @return Collection<ModelInfo>
     */
    public function getModelsByProvider(string $provider): Collection
    {
        return $this->getAllModels()->filter(function (ModelInfo $model) use ($provider) {
            return $model->provider === $provider;
        });
    }

    /**
     * 特定のモデルを取得
     *
     * @param string $modelId
     * @return ModelInfo|null
     */
    public function getModel(string $modelId): ?ModelInfo
    {
        return $this->getAllModels()->first(function (ModelInfo $model) use ($modelId) {
            return $model->id === $modelId;
        });
    }

    /**
     * モデルが存在するかチェック
     *
     * @param string $modelId
     * @return bool
     */
    public function exists(string $modelId): bool
    {
        return $this->getModel($modelId) !== null;
    }

    /**
     * 特定のプロバイダーとモデル名でモデル情報を取得
     *
     * @param string $provider
     * @param string $modelName
     * @return array|null
     */
    public function getModelInfo(string $provider, string $modelName): ?array
    {
        $data = $this->loadYamlData();

        if (!isset($data[$provider])) {
            return null;
        }

        // 直接的なマッチング
        if (isset($data[$provider][$modelName])) {
            $modelData = $data[$provider][$modelName];
            $modelData['provider'] = $provider;
            $modelData['id'] = $modelName;
            return $modelData;
        }

        // モデル名による部分マッチング（model値での検索）
        foreach ($data[$provider] as $modelId => $modelData) {
            if (isset($modelData['model']) && $modelData['model'] === $modelName) {
                $modelData['provider'] = $provider;
                $modelData['id'] = $modelId;
                return $modelData;
            }
        }

        return null;
    }

    /**
     * モデルをYAMLファイルに追加
     *
     * @param ModelInfo $modelInfo
     * @return bool
     */
    public function addModel(ModelInfo $modelInfo): bool
    {
        try {
            $data = $this->loadYamlData();

            // プロバイダーセクションが存在しない場合は作成
            if (!isset($data[$modelInfo->provider])) {
                $data[$modelInfo->provider] = [];
            }

            // モデル情報を追加
            $data[$modelInfo->provider][$modelInfo->id] = $this->modelInfoToArray($modelInfo);

            $this->saveYamlData($data);
            $this->clearCache();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * モデルをYAMLファイルから削除
     *
     * @param string $provider
     * @param string $modelId
     * @return bool
     */
    public function removeModel(string $provider, string $modelId): bool
    {
        try {
            $data = $this->loadYamlData();

            if (isset($data[$provider][$modelId])) {
                unset($data[$provider][$modelId]);

                // プロバイダーセクションが空になった場合は削除
                if (empty($data[$provider])) {
                    unset($data[$provider]);
                }

                $this->saveYamlData($data);
                $this->clearCache();

                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * YAMLファイルの検証
     *
     * @return array 検証結果 ['valid' => bool, 'errors' => array]
     */
    public function validateYaml(): array
    {
        $errors = [];

        try {
            if (!File::exists($this->yamlPath)) {
                $errors[] = "YAML file not found: {$this->yamlPath}";
                return ['valid' => false, 'errors' => $errors];
            }

            $data = $this->loadYamlData();

            if (!is_array($data)) {
                $errors[] = 'YAML file must contain an array/object structure';
                return ['valid' => false, 'errors' => $errors];
            }

            foreach ($data as $provider => $models) {
                if (!is_string($provider)) {
                    $errors[] = "Provider name must be string, got: " . gettype($provider);
                    continue;
                }

                if (!is_array($models)) {
                    $errors[] = "Provider '{$provider}' must contain an array of models";
                    continue;
                }

                foreach ($models as $modelId => $modelData) {
                    $errors = array_merge($errors, $this->validateModelData($provider, $modelId, $modelData));
                }
            }
        } catch (\Exception $e) {
            $errors[] = "YAML parsing error: " . $e->getMessage();
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * キャッシュをクリア
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * YAMLファイルからデータを読み込み
     */
    private function loadYamlData(): array
    {
        if (!File::exists($this->yamlPath)) {
            throw new InvalidConfigException("Models YAML file not found: {$this->yamlPath}");
        }

        $content = File::get($this->yamlPath);

        try {
            $data = Yaml::parse($content);
            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            throw new InvalidConfigException("Invalid YAML format: " . $e->getMessage());
        }
    }

    /**
     * YAMLファイルにデータを保存
     */
    private function saveYamlData(array $data): void
    {
        $yamlContent = Yaml::dump($data, 4, 2);

        // ディレクトリが存在しない場合は作成
        $directory = dirname($this->yamlPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($this->yamlPath, $yamlContent);
    }

    /**
     * YAMLデータからModelInfoコレクションを作成
     *
     * @return Collection<ModelInfo>
     */
    private function loadModelsFromYaml(): Collection
    {
        $data = $this->loadYamlData();
        $models = collect();

        foreach ($data as $provider => $providerModels) {
            if (!is_array($providerModels)) {
                continue;
            }

            foreach ($providerModels as $modelId => $modelData) {
                try {
                    $modelInfo = $this->arrayToModelInfo($provider, $modelId, $modelData);
                    $models->push($modelInfo);
                } catch (\Exception $e) {
                    // ログに記録して続行
                    Log::warning("Failed to parse model {$provider}:{$modelId}", [
                        'error' => $e->getMessage(),
                        'data' => $modelData
                    ]);
                }
            }
        }

        return $models;
    }

    /**
     * 配列データからModelInfoインスタンスを作成
     */
    private function arrayToModelInfo(string $provider, string $modelId, array $data): ModelInfo
    {
        return new ModelInfo(
            id: $modelId,
            name: $data['name'] ?? $modelId,
            provider: $provider,
            type: $data['type'] ?? 'text',
            features: $data['features'] ?? [],
            maxTokens: $data['limits']['max_tokens'] ?? null,
            contextWindow: $data['limits']['context_window'] ?? null,
            description: $data['description'] ?? null,
            pricing: $data['pricing'] ?? [],
            limits: $data['limits'] ?? [],
        );
    }

    /**
     * ModelInfoを配列形式に変換（YAML保存用）
     */
    private function modelInfoToArray(ModelInfo $modelInfo): array
    {
        $data = [
            'provider' => $modelInfo->provider,
            'model' => $modelInfo->id,
            'type' => $modelInfo->type,
        ];

        if (!empty($modelInfo->features)) {
            $data['features'] = array_values($modelInfo->features);
        }

        if (!empty($modelInfo->pricing)) {
            $data['pricing'] = $modelInfo->pricing;
        }

        if ($modelInfo->maxTokens || $modelInfo->contextWindow) {
            $data['limits'] = array_filter([
                'max_tokens' => $modelInfo->maxTokens,
                'context_window' => $modelInfo->contextWindow,
            ]);
        }

        return $data;
    }

    /**
     * 個別モデルデータの検証
     */
    private function validateModelData(string $provider, string $modelId, mixed $modelData): array
    {
        $errors = [];

        if (!is_string($modelId)) {
            $errors[] = "Model ID must be string in provider '{$provider}', got: " . gettype($modelId);
            return $errors;
        }

        if (!is_array($modelData)) {
            $errors[] = "Model '{$provider}:{$modelId}' data must be an array";
            return $errors;
        }

        // 必須フィールドのチェック
        $requiredFields = ['provider', 'model', 'type'];
        foreach ($requiredFields as $field) {
            if (!isset($modelData[$field])) {
                $errors[] = "Model '{$provider}:{$modelId}' missing required field: {$field}";
            }
        }

        // プロバイダーの一致チェック
        if (isset($modelData['provider']) && $modelData['provider'] !== $provider) {
            $errors[] = "Model '{$provider}:{$modelId}' provider mismatch: expected '{$provider}', got '{$modelData['provider']}'";
        }

        // 配列フィールドのチェック
        $arrayFields = ['features', 'pricing', 'limits'];
        foreach ($arrayFields as $field) {
            if (isset($modelData[$field]) && !is_array($modelData[$field])) {
                $errors[] = "Model '{$provider}:{$modelId}' field '{$field}' must be an array";
            }
        }

        return $errors;
    }
}
