<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

final class CostCalculator
{
    public function __construct(
        private array $models,
        private array $pricing
    ) {
    }

    /**
     * リクエストのコストを計算
     */
    public function calculateCost(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens = 0,
        int $reasoningTokens = 0,
        array $imageOptions = []
    ): float {
        $modelConfig = $this->getModelConfig($model);

        if (! $modelConfig) {
            return 0.0;
        }

        $pricing = $modelConfig['pricing'];
        $cost = 0.0;

        // テキストモデルの場合
        if (($modelConfig['type'] ?? 'text') === 'text') {
            // 入力トークンのコスト
            $cost += ($inputTokens / 1_000_000) * $pricing['input'];

            // 出力トークンのコスト
            $cost += ($outputTokens / 1_000_000) * $pricing['output'];

            // キャッシュ入力トークンのコスト（割引価格）
            if ($cachedTokens > 0 && isset($pricing['cached_input'])) {
                $cost += ($cachedTokens / 1_000_000) * $pricing['cached_input'];
            }

            // 推論トークンのコスト（o3, o4-miniなど）
            if ($reasoningTokens > 0 && isset($pricing['reasoning'])) {
                $cost += ($reasoningTokens / 1_000_000) * $pricing['reasoning'];
            }
        }

        // 画像モデルの場合
        if (($modelConfig['type'] ?? 'text') === 'image') {
            $cost += $this->calculateImageCost($pricing, $imageOptions);
        }

        return $this->convertToLocalCurrency($cost);
    }

    /**
     * 画像生成のコストを計算
     */
    private function calculateImageCost(array $pricing, array $options): float
    {
        $quality = $options['quality'] ?? 'standard';
        $size = $options['size'] ?? '1024x1024';
        $count = $options['n'] ?? 1;

        $costPerImage = $pricing[$quality][$size] ?? 0.0;

        return $costPerImage * $count;
    }

    /**
     * USDから現地通貨に変換
     */
    private function convertToLocalCurrency(float $usdCost): float
    {
        $exchangeRate = $this->pricing['exchange_rate'] ?? 1;
        $decimalPlaces = $this->pricing['decimal_places'] ?? 2;

        $localCost = $usdCost * $exchangeRate;

        return round($localCost, $decimalPlaces);
    }

    /**
     * モデル設定を取得
     */
    private function getModelConfig(string $model): ?array
    {
        return $this->models[$model] ?? null;
    }

    /**
     * プロバイダーの利用可能モデル一覧を取得
     */
    public function getProviderModels(string $provider): array
    {
        $models = [];

        foreach ($this->models as $modelName => $config) {
            if ($config['provider'] === $provider) {
                $models[$modelName] = $config;
            }
        }

        return $models;
    }

    /**
     * 特定の機能をサポートするモデル一覧を取得
     */
    public function getModelsByFeature(string $feature): array
    {
        $models = [];

        foreach ($this->models as $modelName => $config) {
            if (in_array($feature, $config['features'] ?? [])) {
                $models[$modelName] = $config;
            }
        }

        return $models;
    }

    /**
     * モデルの制限情報を取得
     */
    public function getModelLimits(string $model): array
    {
        $modelConfig = $this->getModelConfig($model);

        return $modelConfig['limits'] ?? [];
    }

    /**
     * レート制限チェック
     */
    public function checkRateLimit(string $model, int $currentRequests, int $currentTokens): array
    {
        $limits = $this->getModelLimits($model);

        $result = [
            'requests_ok' => true,
            'tokens_ok' => true,
            'requests_remaining' => null,
            'tokens_remaining' => null,
        ];

        if (isset($limits['requests_per_minute'])) {
            $result['requests_ok'] = $currentRequests < $limits['requests_per_minute'];
            $result['requests_remaining'] = max(0, $limits['requests_per_minute'] - $currentRequests);
        }

        if (isset($limits['tokens_per_minute'])) {
            $result['tokens_ok'] = $currentTokens < $limits['tokens_per_minute'];
            $result['tokens_remaining'] = max(0, $limits['tokens_per_minute'] - $currentTokens);
        }

        return $result;
    }

    /**
     * コスト見積もり（トークン数から）
     */
    public function estimateCost(string $model, int $estimatedInputTokens, int $estimatedOutputTokens = 0): float
    {
        return $this->calculateCost(
            model: $model,
            inputTokens: $estimatedInputTokens,
            outputTokens: $estimatedOutputTokens
        );
    }
}
