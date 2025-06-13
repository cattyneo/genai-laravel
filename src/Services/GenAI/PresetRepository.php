<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Exceptions\PromptNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class PresetRepository
{
    private array $presets = [];

    private bool $isWarmed = false;

    public function __construct(
        private string $presetsPath
    ) {}

    /**
     * プリセットを取得
     */
    public function get(string $name): PresetData
    {
        if (! $this->isWarmed) {
            $this->warm();
        }

        if (! isset($this->presets[$name])) {
            throw new PromptNotFoundException("Preset '{$name}' not found");
        }

        return $this->presets[$name];
    }

    /**
     * 全プリセットをキャッシュにロード
     */
    public function warm(): void
    {
        $cacheKey = 'genai:presets:all';

        $this->presets = Cache::remember($cacheKey, 3600, function () {
            return $this->loadPresetsFromDisk();
        });

        $this->isWarmed = true;
    }

    /**
     * キャッシュをクリア
     */
    public function flush(): void
    {
        Cache::forget('genai:presets:all');
        $this->presets = [];
        $this->isWarmed = false;
    }

    /**
     * ディスクからプリセットをロード
     */
    private function loadPresetsFromDisk(): array
    {
        $presets = [];
        $presetsPath = storage_path($this->presetsPath);

        if (! File::exists($presetsPath)) {
            File::makeDirectory($presetsPath, 0755, true);
            $this->createDefaultPresets($presetsPath);
        }

        $files = File::glob("{$presetsPath}/*.yaml");

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $config = Yaml::parseFile($file);

            $presets[$name] = new PresetData(
                name: $name,
                provider: $config['provider'] ?? config('genai.defaults.provider'),
                model: $config['model'] ?? config('genai.defaults.model'),
                systemPrompt: $config['system_prompt'] ?? null,
                options: $config['options'] ?? []
            );
        }

        return $presets;
    }

    /**
     * デフォルトプリセットを作成
     */
    private function createDefaultPresets(string $presetsPath): void
    {
        $defaultPreset = [
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'system_prompt' => 'あなたは親切で有能なAIアシスタントです。ユーザーからの質問や依頼に対して、正確で分かりやすい回答を提供してください。',
            'options' => [
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'top_p' => 0.95,
            ],
        ];

        $askPreset = [
            'provider' => 'openai',
            'model' => 'gpt-4.1-nano',
            'system_prompt' => 'あなたは質問に対して簡潔で正確な回答を提供するAIアシスタントです。',
            'options' => [
                'temperature' => 0.5,
                'max_tokens' => 1500,
            ],
        ];

        $createPreset = [
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'system_prompt' => 'あなたは創造的で革新的なコンテンツを生成するAIアシスタントです。ブログ記事、マーケティングコピー、小説、詩など、様々な創作活動をサポートします。独創性と読みやすさを重視してください。',
            'options' => [
                'temperature' => 0.9,
                'max_tokens' => 4000,
                'top_p' => 0.95,
                'presence_penalty' => 0.1,
                'frequency_penalty' => 0.1,
            ],
        ];

        $analyzePreset = [
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
            'system_prompt' => 'あなたは優秀なデータアナリストです。提供されたデータや情報を深く分析し、インサイトやパターンを発見してください。分析結果は構造化された形で提示し、根拠を明確に示してください。',
            'options' => [
                'temperature' => 0.4,
                'max_tokens' => 3000,
            ],
        ];

        $thinkPreset = [
            'provider' => 'openai',
            'model' => 'o4-mini',
            'system_prompt' => 'あなたは深く考える思考モデルです。複雑な問題に対して段階的に思考を進め、論理的で根拠のある回答を提供してください。',
            'options' => [
                'temperature' => 0.3,
                'max_tokens' => 2500,
            ],
        ];

        $codePreset = [
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'system_prompt' => 'あなたは経験豊富なソフトウェアエンジニアです。高品質で保守性の高いコードを生成し、ベストプラクティスに従った実装を提供してください。コードには適切なコメントと説明を含めてください。',
            'options' => [
                'temperature' => 0.3,
                'max_tokens' => 3000,
                'top_p' => 0.9,
            ],
        ];

        File::put("{$presetsPath}/default.yaml", Yaml::dump($defaultPreset));
        File::put("{$presetsPath}/ask.yaml", Yaml::dump($askPreset));
        File::put("{$presetsPath}/create.yaml", Yaml::dump($createPreset));
        File::put("{$presetsPath}/analyze.yaml", Yaml::dump($analyzePreset));
        File::put("{$presetsPath}/think.yaml", Yaml::dump($thinkPreset));
        File::put("{$presetsPath}/code.yaml", Yaml::dump($codePreset));
    }

    /**
     * 単一プリセット取得
     */
    public function getPreset(string $name): ?array
    {
        $this->warm();

        return $this->presets[$name] ?? null;
    }

    /**
     * 全プリセット取得
     */
    public function getAllPresets(): array
    {
        $this->warm();

        return $this->presets;
    }

    /**
     * プリセット保存
     */
    public function savePreset(string $name, array $preset): void
    {
        $filePath = "{$this->presetsPath}/{$name}.yaml";
        File::put($filePath, Yaml::dump($preset));

        // キャッシュ更新
        $this->presets[$name] = $preset;
        Cache::forget('genai.presets');
    }
}

final class PresetData
{
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $systemPrompt = null,
        public readonly array $options = []
    ) {}
}
