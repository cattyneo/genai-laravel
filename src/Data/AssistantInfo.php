<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Data;

use Carbon\Carbon;

/**
 * OpenAI Assistant情報を格納するデータクラス
 */
final readonly class AssistantInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $instructions,
        public string $model,
        public array $tools,
        public array $fileIds,
        public array $metadata,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?array $responseFormat = null,
        public array $toolResources = [],
        public ?Carbon $createdAt = null,
        public ?Carbon $updatedAt = null,
    ) {
    }

    /**
     * プロンプト用のMarkdownファイルコンテンツを生成
     */
    public function toPromptMarkdown(): string
    {
        $variables = $this->extractVariables($this->instructions);
        $variablesYaml = empty($variables) ? '' : "\nvariables: [".implode(', ', $variables).']';

        return "---
title: {$this->name}
description: {$this->description}{$variablesYaml}
---

{$this->instructions}";
    }

    /**
     * プリセット用のYAMLファイルコンテンツを生成
     */
    public function toPresetYaml(): string
    {
        $provider = 'openai';
        $model = $this->model;
        $systemPrompt = $this->instructions;

        $options = array_filter([
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'max_tokens' => $this->getMaxTokensFromModel($model),
        ], fn ($value) => $value !== null);

        $yaml = "provider: {$provider}\n";
        $yaml .= "model: {$model}\n";
        $yaml .= 'system_prompt: '.json_encode($systemPrompt)."\n";

        if (! empty($options)) {
            $yaml .= "options:\n";
            foreach ($options as $key => $value) {
                $yaml .= "  {$key}: {$value}\n";
            }
        }

        // ツール情報をコメントとして追加
        if (! empty($this->tools)) {
            $yaml .= "\n# Tools configured:\n";
            foreach ($this->tools as $tool) {
                $toolType = $tool['type'] ?? 'unknown';
                $yaml .= "#   - {$toolType}\n";
            }
        }

        // ファイル情報をコメントとして追加
        if (! empty($this->fileIds)) {
            $yaml .= "\n# Files attached:\n";
            foreach ($this->fileIds as $fileId) {
                $yaml .= "#   - {$fileId}\n";
            }
        }

        return $yaml;
    }

    /**
     * プロンプト文字列から変数を抽出
     */
    private function extractVariables(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * モデルから推定最大トークン数を取得
     */
    private function getMaxTokensFromModel(string $model): ?int
    {
        return match (true) {
            str_contains($model, 'gpt-4.1') => 16384,
            str_contains($model, 'gpt-4o') => 16384,
            str_contains($model, 'o3') => 100000,
            str_contains($model, 'o4-mini') => 65536,
            str_contains($model, 'gpt-4') => 8192,
            str_contains($model, 'gpt-3.5') => 4096,
            default => null,
        };
    }
}
