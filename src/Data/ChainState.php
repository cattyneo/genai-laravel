<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Data;

use Spatie\LaravelData\Data;

/**
 * チェーンメソッドの状態を管理するValue Object
 */
class ChainState extends Data
{
    public function __construct(
        public ?string $prompt = null,
        public ?string $systemPrompt = null,
        public ?string $model = null,
        public string $preset = 'default',
        public ?string $provider = null,
        public array $options = [],
        public array $vars = [],
        public bool $stream = false,
    ) {}

    /**
     * 新しいプロンプトでチェーン状態を更新
     */
    public function withPrompt(string $prompt): self
    {
        return new self(
            prompt: $prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * 新しいシステムプロンプトでチェーン状態を更新
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * 新しいモデルでチェーン状態を更新
     */
    public function withModel(string $model): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $model,
            preset: $this->preset,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * 新しいプリセットでチェーン状態を更新
     */
    public function withPreset(string $preset): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $preset,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * 新しいプロバイダーでチェーン状態を更新
     */
    public function withProvider(string $provider): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * オプションをマージしてチェーン状態を更新
     */
    public function withOptions(array $options): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $this->provider,
            options: array_merge($this->options, $options),
            vars: $this->vars,
            stream: $this->stream,
        );
    }

    /**
     * 変数をマージしてチェーン状態を更新
     */
    public function withVars(array $vars): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $this->provider,
            options: $this->options,
            vars: array_merge($this->vars, $vars),
            stream: $this->stream,
        );
    }

    /**
     * ストリーミングを有効にしてチェーン状態を更新
     */
    public function withStream(): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            preset: $this->preset,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: true,
        );
    }

    /**
     * 温度設定を追加してチェーン状態を更新
     */
    public function withTemperature(float $temperature): self
    {
        return $this->withOptions(['temperature' => $temperature]);
    }

    /**
     * 最大トークン数設定を追加してチェーン状態を更新
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return $this->withOptions(['max_tokens' => $maxTokens]);
    }

    /**
     * GenAIRequestDataに変換
     */
    public function toRequestData(): GenAIRequestData
    {
        return new GenAIRequestData(
            prompt: $this->prompt ?? '',
            preset: $this->preset,
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            provider: $this->provider,
            options: $this->options,
            vars: $this->vars,
            stream: $this->stream
        );
    }

    /**
     * 初期状態にリセット
     */
    public function reset(): self
    {
        return new self();
    }

    /**
     * 状態が空かどうかを確認
     */
    public function isEmpty(): bool
    {
        return empty($this->prompt)
            && empty($this->systemPrompt)
            && empty($this->model)
            && $this->preset === 'default'
            && empty($this->provider)
            && empty($this->options)
            && empty($this->vars)
            && !$this->stream;
    }
}
