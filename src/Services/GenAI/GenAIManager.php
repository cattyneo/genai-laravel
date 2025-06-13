<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Actions\RequestAction;
use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Data\ChainState;

final class GenAIManager
{
    private ChainState $chainState;

    public function __construct(
        private RequestAction $requestAction,
        private PromptManager $promptManager,
        private array $providers = []
    ) {
        $this->chainState = new ChainState();
    }

    /**
     * シンプルなテキスト生成
     */
    public function ask(string $prompt, array $options = []): string
    {
        $request = new GenAIRequestData(
            prompt: $prompt,
            options: $options
        );

        $response = ($this->requestAction)($request);

        return $response->content;
    }

    /**
     * 詳細なリクエスト
     */
    public function request(?GenAIRequestData $request = null): GenAIResponseData
    {
        if ($request === null) {
            // チェーンメソッドから実行
            $request = $this->chainState->toRequestData();

            // チェーンをリセット
            $this->chainState = $this->chainState->reset();
        }

        return ($this->requestAction)($request);
    }

    /**
     * プロバイダーを指定
     */
    public function provider(string $provider): self
    {
        $this->chainState = $this->chainState->withProvider($provider);
        return $this;
    }

    /**
     * プリセット機能
     */
    public function preset(string $name): self
    {
        $this->chainState = $this->chainState->withPreset($name);
        return $this;
    }

    /**
     * プロンプト設定
     */
    public function prompt(string $prompt): self
    {
        $this->chainState = $this->chainState->withPrompt($prompt);
        return $this;
    }

    /**
     * システムプロンプト設定
     */
    public function systemPrompt(string $systemPrompt): self
    {
        $this->chainState = $this->chainState->withSystemPrompt($systemPrompt);
        return $this;
    }

    /**
     * モデル設定
     */
    public function model(string $model): self
    {
        $this->chainState = $this->chainState->withModel($model);
        return $this;
    }

    /**
     * オプション設定
     */
    public function options(array $options): self
    {
        $this->chainState = $this->chainState->withOptions($options);
        return $this;
    }

    /**
     * 変数設定
     */
    public function vars(array $vars): self
    {
        $this->chainState = $this->chainState->withVars($vars);
        return $this;
    }

    /**
     * ストリーミング設定
     */
    public function stream(): self
    {
        $this->chainState = $this->chainState->withStream();
        return $this;
    }

    /**
     * 温度設定のヘルパー
     */
    public function temperature(float $temperature): self
    {
        $this->chainState = $this->chainState->withTemperature($temperature);
        return $this;
    }

    /**
     * 最大トークン数設定のヘルパー
     */
    public function maxTokens(int $maxTokens): self
    {
        $this->chainState = $this->chainState->withMaxTokens($maxTokens);
        return $this;
    }

    /**
     * プロンプトテンプレートを指定
     */
    public function promptTemplate(string $name, array $vars = []): self
    {
        $renderedPrompt = $this->promptManager->render($name, $vars);
        $this->chainState = $this->chainState->withPrompt($renderedPrompt);
        return $this;
    }

    /**
     * 利用可能なプロンプトテンプレート一覧を取得
     */
    public function getPromptTemplates(): array
    {
        return $this->promptManager->list();
    }

    /**
     * プロンプト統計を取得
     */
    public function getPromptStats(): array
    {
        return $this->promptManager->getStats();
    }

    /**
     * 現在のチェーン状態を取得（デバッグ用）
     */
    public function getChainState(): ChainState
    {
        return $this->chainState;
    }

    /**
     * チェーン状態が空かどうかを確認
     */
    public function isChainEmpty(): bool
    {
        return $this->chainState->isEmpty();
    }
}
