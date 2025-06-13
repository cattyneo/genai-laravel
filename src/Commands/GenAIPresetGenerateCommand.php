<?php

namespace CattyNeo\LaravelGenAI\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * プリセット生成コマンド
 */
class GenAIPresetGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:preset-generate
                            {name : プリセット名}
                            {--template=default : テンプレートタイプ (default|creative|analytical|coding)}
                            {--provider= : 使用するプロバイダー}
                            {--model= : 使用するモデル}
                            {--overwrite : 既存ファイルを上書き}';

    /**
     * The console command description.
     */
    protected $description = 'GenAIプリセットの雛形を生成';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $template = $this->option('template');
        $provider = $this->option('provider');
        $model = $this->option('model');
        $overwrite = $this->option('overwrite');

        // ファイルパスの構築
        $presetsPath = storage_path('genai/presets');
        $filePath = "{$presetsPath}/{$name}.yaml";

        // 既存ファイルのチェック
        if (File::exists($filePath) && !$overwrite) {
            $this->error("プリセット '{$name}' は既に存在します。");
            $this->line("上書きするには --overwrite オプションを使用してください。");
            return 1;
        }

        try {
            // ディレクトリが存在しない場合は作成
            if (!File::exists($presetsPath)) {
                File::makeDirectory($presetsPath, 0755, true);
                $this->info("ディレクトリを作成しました: {$presetsPath}");
            }

            // プリセットコンテンツを生成
            $content = $this->generatePresetContent($name, $template, $provider, $model);

            // ファイルに書き込み
            File::put($filePath, $content);

            $this->info("✅ プリセット '{$name}' を生成しました");
            $this->line("ファイル: {$filePath}");
            $this->newLine();

            // プレビューを表示
            $this->info("📋 生成されたプリセット内容:");
            $this->line($content);
        } catch (\Exception $e) {
            $this->error("❌ プリセット生成中にエラーが発生しました: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * プリセットコンテンツを生成
     */
    private function generatePresetContent(string $name, string $template, ?string $provider, ?string $model): string
    {
        $templates = [
            'default' => [
                'description' => '汎用的なタスクに適した基本設定',
                'temperature' => 0.7,
                'top_p' => 0.95,
                'max_tokens' => 2000,
                'system_prompt' => 'あなたは親切で知識豊富なAIアシスタントです。質問に対して正確で役立つ回答を提供してください。',
            ],
            'creative' => [
                'description' => 'クリエイティブな作業用の設定',
                'temperature' => 1.0,
                'top_p' => 0.95,
                'max_tokens' => 4000,
                'system_prompt' => 'あなたは創造性豊かなAIアシスタントです。独創的で魅力的なアイデアや表現を提供してください。',
            ],
            'analytical' => [
                'description' => '分析・論理的思考が必要なタスク用の設定',
                'temperature' => 0.3,
                'top_p' => 0.9,
                'max_tokens' => 3000,
                'system_prompt' => 'あなたは論理的で分析的なAIアシスタントです。事実に基づいた客観的な分析と推論を提供してください。',
            ],
            'coding' => [
                'description' => 'プログラミング・技術的なタスク用の設定',
                'temperature' => 0.2,
                'top_p' => 0.95,
                'max_tokens' => 4000,
                'system_prompt' => 'あなたは経験豊富なソフトウェアエンジニアです。高品質で実用的なコードとその説明を提供してください。',
            ],
        ];

        $config = $templates[$template] ?? $templates['default'];

        // YAML内容を構築
        $yaml = "# GenAI Preset: {$name}\n";
        $yaml .= "# Template: {$template}\n";
        $yaml .= "# Generated: " . now()->toDateTimeString() . "\n\n";

        $yaml .= "name: \"{$name}\"\n";
        $yaml .= "description: \"{$config['description']}\"\n\n";

        // プロバイダーとモデル
        if ($provider) {
            $yaml .= "provider: \"{$provider}\"\n";
        }
        if ($model) {
            $yaml .= "model: \"{$model}\"\n";
        } else {
            $yaml .= "# model: \"gpt-4.1-mini\"  # Uncomment and specify model\n";
        }

        $yaml .= "\n";

        // オプション設定
        $yaml .= "options:\n";
        $yaml .= "  temperature: {$config['temperature']}\n";
        $yaml .= "  top_p: {$config['top_p']}\n";
        $yaml .= "  max_tokens: {$config['max_tokens']}\n";

        // テンプレート固有の設定
        if ($template === 'creative') {
            $yaml .= "  presence_penalty: 0.6\n";
            $yaml .= "  frequency_penalty: 0.8\n";
        } elseif ($template === 'analytical') {
            $yaml .= "  presence_penalty: 0.1\n";
            $yaml .= "  frequency_penalty: 0.1\n";
        } else {
            $yaml .= "  presence_penalty: 0.0\n";
            $yaml .= "  frequency_penalty: 0.0\n";
        }

        $yaml .= "\n";

        // システムプロンプト
        $yaml .= "system_prompt: |\n";
        foreach (explode("\n", $config['system_prompt']) as $line) {
            $yaml .= "  {$line}\n";
        }

        $yaml .= "\n";

        // 設定例
        $yaml .= "# Additional settings (optional)\n";
        $yaml .= "# async: true\n";
        $yaml .= "# stream: false\n";
        $yaml .= "# timeout: 30\n";
        $yaml .= "# cache:\n";
        $yaml .= "#   enabled: true\n";
        $yaml .= "#   ttl: 3600\n";

        $yaml .= "\n";

        // 使用例
        $yaml .= "# Usage examples:\n";
        $yaml .= "# GenAI::preset('{$name}')->prompt('Your prompt here')->request();\n";
        $yaml .= "# GenAI::preset('{$name}')->model('gpt-4o')->prompt('Your prompt')->request();\n";

        return $yaml;
    }
}
