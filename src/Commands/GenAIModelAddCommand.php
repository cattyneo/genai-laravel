<?php

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Console\Command;

/**
 * モデル追加コマンド
 */
class GenAIModelAddCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:model-add
                            {provider : プロバイダー名 (openai|gemini|claude|grok)}
                            {model : モデルID}
                            {--name= : モデル表示名}
                            {--type=text : モデルタイプ (text|image|audio|vision)}
                            {--features=* : 機能リスト}
                            {--max-tokens= : 最大トークン数}
                            {--context-window= : コンテキストウィンドウサイズ}
                            {--description= : モデル説明}
                            {--pricing-input= : 入力価格 ($/1M tokens)}
                            {--pricing-output= : 出力価格 ($/1M tokens)}
                            {--dry-run : 実際には追加せずにプレビューのみ}';

    /**
     * The console command description.
     */
    protected $description = 'YAMLファイルに新しいモデルを追加';

    private ModelRepository $modelRepository;

    public function __construct(ModelRepository $modelRepository)
    {
        parent::__construct();
        $this->modelRepository = $modelRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $provider = $this->argument('provider');
        $modelId = $this->argument('model');
        $dryRun = $this->option('dry-run');

        // プロバイダーの検証
        $validProviders = ['openai', 'gemini', 'claude', 'grok'];
        if (! in_array($provider, $validProviders)) {
            $this->error('無効なプロバイダーです。利用可能: '.implode(', ', $validProviders));

            return 1;
        }

        // 既存モデルのチェック
        if ($this->modelRepository->exists($modelId)) {
            $this->error("モデル '{$modelId}' は既に存在します。");

            return 1;
        }

        // ModelInfoインスタンスを作成
        $modelInfo = $this->createModelInfo($provider, $modelId);

        // プレビュー表示
        $this->displayModelPreview($modelInfo);

        if ($dryRun) {
            $this->info('Dry-runモードです。実際には追加されませんでした。');

            return 0;
        }

        // 確認
        if (! $this->confirm('このモデルをYAMLファイルに追加しますか？')) {
            $this->info('キャンセルされました。');

            return 0;
        }

        // モデルを追加
        try {
            $success = $this->modelRepository->addModel($modelInfo);

            if ($success) {
                $this->info("✅ モデル '{$modelId}' を正常に追加しました。");
                $this->line('ファイル: '.storage_path('genai/models.yaml'));
            } else {
                $this->error('❌ モデルの追加に失敗しました。');

                return 1;
            }
        } catch (\Exception $e) {
            $this->error('❌ エラーが発生しました: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * ModelInfoインスタンスを作成
     */
    private function createModelInfo(string $provider, string $modelId): ModelInfo
    {
        $features = $this->option('features') ?: [];
        $pricing = [];

        // 価格情報の構築
        if ($this->option('pricing-input')) {
            $pricing['input'] = (float) $this->option('pricing-input');
        }
        if ($this->option('pricing-output')) {
            $pricing['output'] = (float) $this->option('pricing-output');
        }

        // 制限情報の構築
        $limits = [];
        if ($this->option('max-tokens')) {
            $limits['max_tokens'] = (int) $this->option('max-tokens');
        }
        if ($this->option('context-window')) {
            $limits['context_window'] = (int) $this->option('context-window');
        }

        return new ModelInfo(
            id: $modelId,
            name: $this->option('name') ?: $modelId,
            provider: $provider,
            type: $this->option('type') ?: 'text',
            features: $features,
            maxTokens: $limits['max_tokens'] ?? null,
            contextWindow: $limits['context_window'] ?? null,
            description: $this->option('description'),
            pricing: $pricing,
            limits: $limits,
        );
    }

    /**
     * モデル情報のプレビューを表示
     */
    private function displayModelPreview(ModelInfo $modelInfo): void
    {
        $this->info('📋 追加予定モデル情報:');
        $this->newLine();

        $data = [
            ['項目', '値'],
            ['ID', $modelInfo->id],
            ['名前', $modelInfo->name],
            ['プロバイダー', $modelInfo->provider],
            ['タイプ', $modelInfo->type],
            ['機能', implode(', ', $modelInfo->features) ?: 'なし'],
            ['最大トークン', $modelInfo->maxTokens ? number_format($modelInfo->maxTokens) : 'N/A'],
            ['コンテキストウィンドウ', $modelInfo->contextWindow ? number_format($modelInfo->contextWindow) : 'N/A'],
            ['説明', $modelInfo->description ?: 'なし'],
        ];

        // 価格情報があれば追加
        if (! empty($modelInfo->pricing)) {
            if (isset($modelInfo->pricing['input'])) {
                $data[] = ['入力価格', '$'.$modelInfo->pricing['input'].'/1M tokens'];
            }
            if (isset($modelInfo->pricing['output'])) {
                $data[] = ['出力価格', '$'.$modelInfo->pricing['output'].'/1M tokens'];
            }
        }

        $this->table(['項目', '値'], array_slice($data, 1));
        $this->newLine();
    }
}
