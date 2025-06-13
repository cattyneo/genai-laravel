<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Exceptions\PromptNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class PromptManager
{
    private array $prompts = [];

    private string $promptsPath;

    public function __construct(string $promptsPath)
    {
        $this->promptsPath = $promptsPath;
        $this->warm();
    }

    /**
     * プロンプトを取得
     */
    public function get(string $name): string
    {
        if (! isset($this->prompts[$name])) {
            throw new PromptNotFoundException("Prompt '{$name}' not found");
        }

        return $this->prompts[$name]['content'];
    }

    /**
     * 変数付きプロンプトを取得・レンダリング
     */
    public function render(string $name, array $vars = []): string
    {
        $content = $this->get($name);

        // 変数置換
        foreach ($vars as $key => $value) {
            $content = str_replace("{{$key}}", (string) $value, $content);
        }

        return $content;
    }

    /**
     * プロンプトのメタデータを取得
     */
    public function getMeta(string $name): array
    {
        if (! isset($this->prompts[$name])) {
            throw new PromptNotFoundException("Prompt '{$name}' not found");
        }

        return $this->prompts[$name]['meta'];
    }

    /**
     * 利用可能なプロンプト一覧を取得
     */
    public function list(): array
    {
        return array_keys($this->prompts);
    }

    /**
     * プロンプトの存在確認
     */
    public function exists(string $name): bool
    {
        return isset($this->prompts[$name]);
    }

    /**
     * プロンプトを動的に追加
     */
    public function add(string $name, string $content, array $meta = []): void
    {
        $this->prompts[$name] = [
            'content' => $content,
            'meta' => $meta,
        ];
    }

    /**
     * プロンプトキャッシュをクリア・再読み込み
     */
    public function refresh(): void
    {
        $this->prompts = [];
        Cache::forget('genai_prompts');
        $this->warm();
    }

    /**
     * 全プロンプトを事前読み込み（キャッシュ付き）
     */
    public function warm(): void
    {
        $cacheKey = 'genai_prompts';
        $cachedPrompts = Cache::get($cacheKey);

        if ($cachedPrompts) {
            $this->prompts = $cachedPrompts;

            return;
        }

        $this->prompts = $this->loadPromptsFromDisk();

        // 1時間キャッシュ
        Cache::put($cacheKey, $this->prompts, 3600);
    }

    /**
     * ディスクからプロンプトファイルを読み込み
     */
    private function loadPromptsFromDisk(): array
    {
        $prompts = [];
        $promptsPath = storage_path($this->promptsPath);

        if (! File::exists($promptsPath)) {
            File::makeDirectory($promptsPath, 0755, true);
            $this->createDefaultPrompts($promptsPath);
        }

        $files = File::allFiles($promptsPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $relativePath = str_replace($promptsPath.'/', '', $file->getRealPath());
                $name = str_replace(['/', '.md'], ['_', ''], $relativePath);

                $content = File::get($file->getRealPath());
                $parsed = $this->parseMarkdownPrompt($content);

                $prompts[$name] = [
                    'content' => $parsed['content'],
                    'meta' => $parsed['meta'],
                    'file' => $relativePath,
                ];
            }
        }

        return $prompts;
    }

    /**
     * Markdownプロンプトファイルをパース
     */
    private function parseMarkdownPrompt(string $content): array
    {
        $meta = [];
        $promptContent = $content;

        // FrontMatter (YAML) の解析
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            $yamlContent = $matches[1];
            $promptContent = trim($matches[2]);

            // 簡易的なYAMLパース
            $lines = explode("\n", $yamlContent);
            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $lineMatches)) {
                    $key = $lineMatches[1];
                    $value = trim($lineMatches[2], '"\'');

                    // 配列形式の値を処理
                    if (preg_match('/^\[(.*)\]$/', $value, $arrayMatches)) {
                        $items = explode(',', $arrayMatches[1]);
                        $meta[$key] = array_map('trim', $items);
                    } else {
                        $meta[$key] = $value;
                    }
                }
            }
        }

        return [
            'content' => $promptContent,
            'meta' => $meta,
        ];
    }

    /**
     * デフォルトプロンプトファイルを作成
     */
    private function createDefaultPrompts(string $path): void
    {
        $defaultPrompts = [
            'default.md' => [
                'meta' => [
                    'title' => 'デフォルトプロンプト',
                    'description' => '一般的なタスク用のプロンプト',
                    'variables' => ['topic'],
                ],
                'content' => '{{topic}}について詳しく説明してください。',
            ],
            'create.md' => [
                'meta' => [
                    'title' => 'ブログ記事作成',
                    'description' => 'SEOに最適化されたブログ記事を生成',
                    'variables' => ['topic', 'keywords', 'length'],
                ],
                'content' => "以下のトピックについて、SEOに最適化されたブログ記事を生成してください。\n\nトピック: {{topic}}\nキーワード: {{keywords}}\n文字数: {{length}}\n\n記事は読者にとって価値のある内容で、わかりやすく構成してください。",
            ],
            'review.md' => [
                'meta' => [
                    'title' => 'コードレビュー',
                    'description' => 'コードの品質評価とアドバイス',
                    'variables' => ['code', 'language'],
                ],
                'content' => "以下の{{language}}コードをレビューして、改善点やベストプラクティスのアドバイスを提供してください。\n\n```\n{{code}}\n```\n\n以下の観点から評価してください：\n- 可読性\n- パフォーマンス\n- セキュリティ\n- 保守性",
            ],
            'analyze.md' => [
                'meta' => [
                    'title' => 'メタ情報抽出',
                    'description' => 'テキストからメタ情報を抽出',
                    'variables' => ['text'],
                ],
                'content' => "以下のテキストから、メタ情報を抽出して構造化された形で提示してください。\n\nテキスト:\n{{text}}\n\n抽出する情報：\n1. **タイトル**: テキストの主要なタイトルまたは見出し\n2. **要約**: 内容の簡潔な要約（100-200文字程度）\n3. **カテゴリ**: コンテンツの分類（記事、レポート、ニュース、技術文書など）\n4. **タグ**: 関連するキーワードやトピック（3-5個）\n5. **タイムスタンプ**: 記載されている日付・時刻情報（あれば）\n\n結果は以下のJSON形式で提示してください：\n```json\n{\n  \"title\": \"抽出されたタイトル\",\n  \"summary\": \"内容の要約\",\n  \"category\": \"カテゴリ名\",\n  \"tags\": [\"タグ1\", \"タグ2\", \"タグ3\"],\n  \"timestamp\": \"YYYY-MM-DD HH:mm:ss または null\"\n}\n```",
            ],
        ];

        foreach ($defaultPrompts as $filename => $prompt) {
            $filePath = $path.'/'.$filename;
            $directory = dirname($filePath);

            if (! File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            $yamlMeta = '';
            foreach ($prompt['meta'] as $key => $value) {
                if (is_array($value)) {
                    $yamlMeta .= "{$key}: [".implode(', ', $value)."]\n";
                } else {
                    $yamlMeta .= "{$key}: {$value}\n";
                }
            }

            $fileContent = "---\n{$yamlMeta}---\n\n{$prompt['content']}";
            File::put($filePath, $fileContent);
        }
    }

    /**
     * プロンプト統計を取得
     */
    public function getStats(): array
    {
        return [
            'total_prompts' => count($this->prompts),
            'prompts_path' => $this->promptsPath,
            'categories' => $this->getCategories(),
            'variables_used' => $this->getVariablesUsed(),
        ];
    }

    /**
     * プロンプトカテゴリを取得
     */
    private function getCategories(): array
    {
        $categories = [];
        foreach ($this->prompts as $name => $prompt) {
            $parts = explode('_', $name);
            if (count($parts) > 1) {
                $category = $parts[0];
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }
        }

        return $categories;
    }

    /**
     * 使用されている変数を取得
     */
    private function getVariablesUsed(): array
    {
        $variables = [];
        foreach ($this->prompts as $prompt) {
            if (isset($prompt['meta']['variables']) && is_array($prompt['meta']['variables'])) {
                $variables = array_merge($variables, $prompt['meta']['variables']);
            }
        }

        return array_unique($variables);
    }
}
