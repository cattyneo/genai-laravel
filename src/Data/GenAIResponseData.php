<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Data;

use Spatie\LaravelData\Data;

class GenAIResponseData extends Data
{
    public function __construct(
        public string $content,
        public array $usage = [],
        public float $cost = 0.0,
        public array $meta = [],
        public ?string $error = null,
        public bool $cached = false,
        public int $responseTimeMs = 0,
    ) {
    }

    /**
     * 生のAPIレスポンスからGenAIResponseDataを作成
     */
    public static function fromRaw(array $raw): self
    {
        // OpenAI API レスポンス形式を想定
        $content = $raw['choices'][0]['message']['content'] ?? '';
        $usage = $raw['usage'] ?? [];

        return new self(
            content: $content,
            usage: $usage,
            cost: 0.0, // コスト計算は後で実装
            meta: $raw,
        );
    }
}
