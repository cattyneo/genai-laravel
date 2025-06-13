<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;

/**
 * リクエスト設定の解決を担当するクラス
 */
final class RequestConfiguration
{
    public function __construct(
        private PresetRepository $presets,
        private array $defaults = []
    ) {}

    /**
     * リクエストの設定を解決する
     */
    public function resolve(GenAIRequestData $request): ResolvedRequestConfig
    {
        $preset = $this->presets->get($request->preset);

        $provider = $request->provider ?? $preset->provider;
        $model = $request->model ?? $preset->model;
        $systemPrompt = $request->systemPrompt ?? $preset->systemPrompt;

        $options = array_merge(
            $this->defaults['options'] ?? [],
            $preset->options,
            $request->options
        );

        // モデル固有の調整
        $options = $this->adjustOptionsForModel($model, $options);

        return new ResolvedRequestConfig(
            provider: $provider,
            model: $model,
            prompt: $request->prompt,
            systemPrompt: $systemPrompt,
            options: $options,
            vars: $request->vars,
            stream: $request->stream
        );
    }

    /**
     * モデル固有のオプション調整
     */
    private function adjustOptionsForModel(string $model, array $options): array
    {
        // o4-miniなど推論モデルの場合、パラメータを調整
        if (str_contains($model, 'o4-mini') || str_contains($model, 'o3')) {
            if (isset($options['max_tokens'])) {
                $options['max_completion_tokens'] = $options['max_tokens'];
                unset($options['max_tokens']);
            }
            // 推論モデルでは温度設定を削除（デフォルト値1のみサポート）
            unset($options['temperature']);
            unset($options['top_p']);
        }

        return $options;
    }

    /**
     * プロバイダー設定を取得
     */
    public function getProviderConfig(string $provider): array
    {
        $config = config("genai.providers.{$provider}");

        if (! $config) {
            throw new \InvalidArgumentException("Provider '{$provider}' configuration not found");
        }

        return $config;
    }
}
