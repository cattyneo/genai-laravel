<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Data;

use Spatie\LaravelData\Data;

class GenAIRequestData extends Data
{
    public function __construct(
        public string $prompt,
        public string $preset = 'default',
        public ?string $systemPrompt = null,
        public ?string $model = null,
        public ?string $provider = null,
        public array $options = [],
        public array $vars = [],
        public bool $stream = false,
    ) {}
}
