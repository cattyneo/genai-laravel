<?php

namespace CattyNeo\LaravelGenAI\Data;

use Carbon\Carbon;

/**
 * 各プロバイダーのモデル情報を統一化するDTO
 */
class ModelInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public string $provider,
        public string $type = 'text',
        public array $features = [],
        public ?int $maxTokens = null,
        public ?int $contextWindow = null,
        public ?string $description = null,
        public ?Carbon $createdAt = null,
        public array $pricing = [],
        public array $limits = [],
        public array $supportedMethods = [],
        public ?string $baseModelId = null,
        public ?string $version = null,
    ) {
    }

    /**
     * 配列からModelInfoインスタンスを作成
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'] ?? $data['id'],
            provider: $data['provider'],
            type: $data['type'] ?? 'text',
            features: $data['features'] ?? [],
            maxTokens: $data['max_tokens'] ?? $data['maxTokens'] ?? null,
            contextWindow: $data['context_window'] ?? $data['contextWindow'] ?? null,
            description: $data['description'] ?? null,
            createdAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            pricing: $data['pricing'] ?? [],
            limits: $data['limits'] ?? [],
            supportedMethods: $data['supported_methods'] ?? $data['supportedMethods'] ?? [],
            baseModelId: $data['base_model_id'] ?? $data['baseModelId'] ?? null,
            version: $data['version'] ?? null,
        );
    }

    /**
     * 配列形式で出力
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'type' => $this->type,
            'features' => $this->features,
            'max_tokens' => $this->maxTokens,
            'context_window' => $this->contextWindow,
            'description' => $this->description,
            'created_at' => $this->createdAt?->toISOString(),
            'pricing' => $this->pricing,
            'limits' => $this->limits,
            'supported_methods' => $this->supportedMethods,
            'base_model_id' => $this->baseModelId,
            'version' => $this->version,
        ];
    }

    /**
     * 表示用の簡潔な情報を取得
     */
    public function getSummary(): string
    {
        $features = empty($this->features) ? '' : ' ['.implode(', ', $this->features).']';
        $tokens = $this->maxTokens ? " ({$this->maxTokens} tokens)" : '';

        return "{$this->name} ({$this->provider}){$tokens}{$features}";
    }
}
