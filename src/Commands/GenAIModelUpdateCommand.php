<?php

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GeminiFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\ClaudeFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GrokFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class GenAIModelUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:model-update
                           {--provider= : 特定のプロバイダーのみ更新 (openai, gemini, claude, grok)}
                           {--force : 既存のモデル情報を上書き}
                           {--dry-run : 実際には更新せず、プレビューのみ表示}
                           {--backup : 更新前にバックアップを作成}';

    /**
     * The console command description.
     */
    protected $description = 'APIからモデル情報を取得してYAMLファイルを更新します';

    private ModelRepository $modelRepository;
    private string $yamlPath;
    private array $fetchers = [];

    public function __construct()
    {
        parent::__construct();
        $this->yamlPath = storage_path('genai/models.yaml');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 GenAI Models Update');
        $this->line('=====================================');

        // 依存関係の解決
        $this->modelRepository = app(ModelRepository::class);
        $this->initializeFetchers();

        // オプションの処理
        $provider = $this->option('provider');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');

        if ($provider && !array_key_exists($provider, $this->fetchers)) {
            $this->error("❌ 無効なプロバイダー: {$provider}");
            $this->line("利用可能なプロバイダー: " . implode(', ', array_keys($this->fetchers)));
            return 1;
        }

        // バックアップの作成
        if ($backup && !$dryRun) {
            $this->createBackup();
        }

        // 現在のYAMLデータを読み込み
        $currentData = $this->loadCurrentYaml();
        $updatedData = $currentData;

        // 更新対象のプロバイダーを決定
        $targetProviders = $provider ? [$provider] : array_keys($this->fetchers);

        $this->line("\n📡 API接続テスト...");

        $totalUpdated = 0;
        $errors = [];

        foreach ($targetProviders as $providerName) {
            $fetcher = $this->fetchers[$providerName];

            $this->line("Testing {$providerName}...");

            if (!$fetcher->isAvailable()) {
                $message = "⚠️ {$providerName}: APIキーまたは設定が不足しています";
                $this->warn($message);
                $errors[] = $message;
                continue;
            }

            try {
                $models = $fetcher->fetchModels();

                if ($models->isEmpty()) {
                    $message = "⚠️ {$providerName}: モデル情報が取得できませんでした";
                    $this->warn($message);
                    $errors[] = $message;
                    continue;
                }

                $this->info("✅ {$providerName}: {$models->count()} モデルを取得");

                // モデル情報をYAML形式に変換
                $yamlModels = $this->convertModelsToYaml($models);

                // 現在のデータとマージまたは置換
                if ($force || !isset($updatedData[$providerName])) {
                    $updatedData[$providerName] = $yamlModels;
                    $totalUpdated += $models->count();
                } else {
                    // 既存データと新規データをマージ
                    $merged = $this->mergeModelData($updatedData[$providerName], $yamlModels);
                    $updatedData[$providerName] = $merged;
                    $totalUpdated += count($yamlModels);
                }
            } catch (\Exception $e) {
                $message = "❌ {$providerName}: " . $e->getMessage();
                $this->error($message);
                $errors[] = $message;
            }
        }

        // 結果の表示
        $this->displayResults($updatedData, $totalUpdated, $errors, $dryRun);

        // 実際の更新
        if (!$dryRun && $totalUpdated > 0) {
            $this->updateYamlFile($updatedData);
            $this->info("✅ YAMLファイルを更新しました: {$this->yamlPath}");

            // キャッシュをクリア
            $this->modelRepository->clearCache();
            $this->info("🧹 モデルキャッシュをクリアしました");

            // 検証の実行
            $this->call('genai:model-validate');
        }

        return empty($errors) ? 0 : 1;
    }

    /**
     * Fetcherを初期化
     */
    private function initializeFetchers(): void
    {
        $this->fetchers = [
            'openai' => app(OpenAIFetcher::class),
            'gemini' => app(GeminiFetcher::class),
            'claude' => app(ClaudeFetcher::class),
            'grok' => app(GrokFetcher::class),
        ];
    }

    /**
     * バックアップを作成
     */
    private function createBackup(): void
    {
        if (!File::exists($this->yamlPath)) {
            $this->warn("⚠️ YAMLファイルが存在しないため、バックアップは作成されません");
            return;
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupPath = $this->yamlPath . ".backup_{$timestamp}";

        File::copy($this->yamlPath, $backupPath);
        $this->info("💾 バックアップ作成: {$backupPath}");
    }

    /**
     * 現在のYAMLデータを読み込み
     */
    private function loadCurrentYaml(): array
    {
        if (!File::exists($this->yamlPath)) {
            $this->info("📝 新しいYAMLファイルを作成します");
            return [];
        }

        try {
            $content = File::get($this->yamlPath);
            $data = Yaml::parse($content);
            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            $this->warn("⚠️ 既存のYAMLファイルの読み込みに失敗: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ModelInfoオブジェクトをYAML配列に変換
     * モデル名の正規化と重複処理を行う
     */
    private function convertModelsToYaml(Collection $models): array
    {
        $yamlModels = [];
        $modelsByBaseKey = [];

        // まず全モデルを処理して基本キーでグループ化
        foreach ($models as $model) {
            $limits = $model->limits;
            if ($model->maxTokens) {
                $limits['max_tokens'] = $model->maxTokens;
            }
            if ($model->contextWindow) {
                $limits['context_window'] = $model->contextWindow;
            }

            $modelData = [
                'provider' => $model->provider,
                'model' => $model->name,
                'type' => $model->type,
                'features' => $model->features,
                'pricing' => $model->pricing,
                'limits' => $limits,
            ];

            // nullや空の値を除去
            $modelData = array_filter($modelData, function ($value) {
                return !is_null($value) && $value !== [] && $value !== '';
            });

            // 正規化されたキーを生成
            $baseKey = $this->normalizeModelKey($model->id);
            $originalKey = $model->id;

            // グループ化
            if (!isset($modelsByBaseKey[$baseKey])) {
                $modelsByBaseKey[$baseKey] = [];
            }

            $modelsByBaseKey[$baseKey][] = [
                'original_key' => $originalKey,
                'data' => $modelData,
                'is_base' => $originalKey === $baseKey, // 修飾子なしかどうか
            ];
        }

        // 重複処理: 修飾子なしを優先
        foreach ($modelsByBaseKey as $baseKey => $modelGroup) {
            if (count($modelGroup) === 1) {
                // 重複なし
                $yamlModels[$baseKey] = $modelGroup[0]['data'];
            } else {
                // 重複あり: 修飾子なしを優先
                $selectedModel = null;

                // まず修飾子なしを探す
                foreach ($modelGroup as $modelInfo) {
                    if ($modelInfo['is_base']) {
                        $selectedModel = $modelInfo;
                        break;
                    }
                }

                // 修飾子なしがない場合は最初のモデルを選択
                if (!$selectedModel) {
                    $selectedModel = $modelGroup[0];
                }

                $yamlModels[$baseKey] = $selectedModel['data'];

                // デバッグ情報出力
                $skippedKeys = array_map(function ($model) use ($selectedModel) {
                    return $model['original_key'];
                }, array_filter($modelGroup, function ($model) use ($selectedModel) {
                    return $model['original_key'] !== $selectedModel['original_key'];
                }));

                if (!empty($skippedKeys)) {
                    $this->line("🔄 重複処理: {$baseKey} を選択、スキップ: " . implode(', ', $skippedKeys));
                }
            }
        }

        return $yamlModels;
    }

    /**
     * モデル名を正規化してキーを生成
     * 日付や修飾子を除去する
     */
    private function normalizeModelKey(string $modelId): string
    {
        // 日付パターン (-YYYY-MM-DD, -MMDD, -YYYYMMDD)
        $normalized = preg_replace('/-20\d{2}-\d{2}-\d{2}$/', '', $modelId);
        $normalized = preg_replace('/-20\d{6}$/', '', $normalized);
        $normalized = preg_replace('/-\d{4}$/', '', $normalized);

        // 修飾子パターン (-preview, -beta, -latest, -experimental 等)
        $suffixes = [
            '-preview',
            '-beta',
            '-latest',
            '-experimental',
            '-exp',
            '-turbo',
            '-instruct',
            '-001',
            '-002',
            '-003',
            '-004',
            '-1106',
            '-0125',
            '-0613',
            '-0314',
            '-0924',
            '-0827',
            '-hd',
            '-realtime',
            '-audio',
            '-vision',
            '-search',
            '-transcribe',
            '-tts',
            '-thinking',
            '-mini',
            '-fast',
            '-tuning',
            '-8b',
            '-lite',
            '-image-generation',
            '-pro',
            '-flash',
            '-sonnet',
            '-opus',
            '-haiku'
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                $normalized = substr($normalized, 0, -strlen($suffix));
                break; // 一つずつ除去
            }
        }

        // 特殊パターンの処理
        $normalized = preg_replace('/-[a-z0-9]{4,}$/', '', $normalized); // 長い修飾子
        $normalized = preg_replace('/-\d{2}-\d{2}$/', '', $normalized);   // 月日パターン

        return $normalized;
    }

    /**
     * 既存データと新規データをマージ
     */
    private function mergeModelData(array $existing, array $new): array
    {
        foreach ($new as $modelId => $modelData) {
            if (!isset($existing[$modelId])) {
                $existing[$modelId] = $modelData;
            } else {
                // 既存データを新しいデータで更新
                $existing[$modelId] = array_merge($existing[$modelId], $modelData);
            }
        }

        return $existing;
    }

    /**
     * 結果を表示
     */
    private function displayResults(array $updatedData, int $totalUpdated, array $errors, bool $dryRun): void
    {
        $this->line("\n📊 更新結果");
        $this->line("========================");

        // 統計情報
        $totalModels = 0;
        foreach ($updatedData as $provider => $models) {
            $count = count($models);
            $totalModels += $count;
            $this->line("  {$provider}: {$count} モデル");
        }

        $this->line("  合計: {$totalModels} モデル");
        $this->line("  更新: {$totalUpdated} モデル");

        if (!empty($errors)) {
            $this->line("\n⚠️  エラー:");
            foreach ($errors as $error) {
                $this->line("  • {$error}");
            }
        }

        if ($dryRun) {
            $this->line("\n🔍 ドライランモード: 実際の更新は行われませんでした");
            $this->line("実際に更新するには --dry-run オプションを外してください");
        }
    }

    /**
     * YAMLファイルを更新
     */
    private function updateYamlFile(array $data): void
    {
        // ディレクトリが存在しない場合は作成
        $directory = dirname($this->yamlPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // コメントヘッダーを追加
        $header = [
            '# GenAI Models Configuration',
            '# This file contains all model definitions for different providers',
            '# Last updated: ' . now()->toDateTimeString(),
            '# Updated by: genai:model-update command',
            '',
        ];

        $yamlContent = implode("\n", $header) . Yaml::dump($data, 4, 2);

        File::put($this->yamlPath, $yamlContent);
    }
}
