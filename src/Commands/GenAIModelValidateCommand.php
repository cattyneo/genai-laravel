<?php

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Console\Command;

/**
 * YAML検証コマンド
 */
class GenAIModelValidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:model-validate
                            {--fix : 可能な修正を自動で適用}
                            {--details : 詳細な情報を表示}';

    /**
     * The console command description.
     */
    protected $description = 'models.yamlファイルの構文と整合性をチェック';

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
        $fix = $this->option('fix');
        $details = $this->option('details');

        $this->info("🔍 GenAI Models YAML Validation");
        $this->line("File: " . storage_path('genai/models.yaml'));
        $this->newLine();

        try {
            // YAML構文チェック
            $this->info("Step 1: YAML構文チェック...");
            $validation = $this->modelRepository->validateYaml();

            if ($validation['valid']) {
                $this->info("✅ YAML構文: 正常");
            } else {
                $this->error("❌ YAML構文: エラーが見つかりました");
                $this->displayErrors($validation['errors']);

                if (!$fix) {
                    $this->newLine();
                    $this->info("修正するには --fix オプションを使用してください");
                    return 1;
                }
            }

            // モデル読み込みテスト
            $this->newLine();
            $this->info("Step 2: モデル読み込みテスト...");

            $models = $this->modelRepository->getAllModels();
            $modelCount = $models->count();

            if ($modelCount > 0) {
                $this->info("✅ モデル読み込み: {$modelCount} モデルを正常に読み込みました");

                if ($details) {
                    $this->displayModelsSummary($models);
                }
            } else {
                $this->warn("⚠️ モデル読み込み: モデルが見つかりませんでした");
            }

            // 統計情報の表示
            $this->newLine();
            $this->info("Step 3: 統計情報");
            $this->displayStatistics($models);

            // 重複チェック
            $this->newLine();
            $this->info("Step 4: 重複チェック...");
            $duplicates = $this->checkDuplicates($models);

            if (empty($duplicates)) {
                $this->info("✅ 重複チェック: 重複するモデルIDは見つかりませんでした");
            } else {
                $this->error("❌ 重複チェック: 重複するモデルIDが見つかりました");
                $this->displayDuplicates($duplicates);
            }

            // 設定整合性チェック
            $this->newLine();
            $this->info("Step 5: 設定整合性チェック...");
            $configIssues = $this->checkConfigConsistency($models);

            if (empty($configIssues)) {
                $this->info("✅ 設定整合性: 問題は見つかりませんでした");
            } else {
                $this->warn("⚠️ 設定整合性: 以下の問題が見つかりました");
                foreach ($configIssues as $issue) {
                    $this->line("  • {$issue}");
                }
            }

            // 最終結果
            $this->newLine();
            $totalIssues = count($validation['errors']) + count($duplicates) + count($configIssues);

            if ($totalIssues === 0) {
                $this->info("🎉 検証完了: すべてのチェックに合格しました！");
                return 0;
            } else {
                $this->error("📋 検証完了: {$totalIssues} 件の問題が見つかりました");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("❌ 検証中にエラーが発生しました: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * エラーを表示
     */
    private function displayErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $this->line("  • {$error}");
        }
    }

    /**
     * モデルサマリーを表示
     */
    private function displayModelsSummary(\Illuminate\Support\Collection $models): void
    {
        $this->newLine();
        $this->line("読み込まれたモデル:");

        $grouped = $models->groupBy('provider');
        foreach ($grouped as $provider => $providerModels) {
            $this->line("  {$provider}: {$providerModels->count()} モデル");
            if ($this->option('details')) {
                foreach ($providerModels as $model) {
                    $this->line("    - {$model->id} ({$model->type})");
                }
            }
        }
    }

    /**
     * 統計情報を表示
     */
    private function displayStatistics(\Illuminate\Support\Collection $models): void
    {
        $byProvider = $models->groupBy('provider');
        $byType = $models->groupBy('type');

        $data = [
            ['項目', '値'],
            ['総モデル数', $models->count()],
            ['プロバイダー数', $byProvider->count()],
        ];

        // プロバイダー別
        foreach ($byProvider as $provider => $providerModels) {
            $data[] = ["  └ {$provider}", $providerModels->count()];
        }

        // タイプ別
        $data[] = ['タイプ別', ''];
        foreach ($byType as $type => $typeModels) {
            $data[] = ["  └ {$type}", $typeModels->count()];
        }

        $this->table(['項目', '値'], array_slice($data, 1));
    }

    /**
     * 重複チェック
     */
    private function checkDuplicates(\Illuminate\Support\Collection $models): array
    {
        $duplicates = [];
        $seen = [];

        foreach ($models as $model) {
            if (isset($seen[$model->id])) {
                $duplicates[] = [
                    'id' => $model->id,
                    'providers' => [$seen[$model->id], $model->provider]
                ];
            } else {
                $seen[$model->id] = $model->provider;
            }
        }

        return $duplicates;
    }

    /**
     * 重複を表示
     */
    private function displayDuplicates(array $duplicates): void
    {
        foreach ($duplicates as $duplicate) {
            $providers = implode(', ', $duplicate['providers']);
            $this->line("  • {$duplicate['id']} (プロバイダー: {$providers})");
        }
    }

    /**
     * 設定整合性チェック
     */
    private function checkConfigConsistency(\Illuminate\Support\Collection $models): array
    {
        $issues = [];
        $configProviders = array_keys(config('genai.providers', []));

        foreach ($models as $model) {
            // 設定されていないプロバイダーのチェック
            if (!in_array($model->provider, $configProviders)) {
                $issues[] = "モデル '{$model->id}' のプロバイダー '{$model->provider}' がconfig/genai.phpで設定されていません";
            }

            // 異常な値のチェック
            if ($model->maxTokens && $model->maxTokens < 1) {
                $issues[] = "モデル '{$model->id}' の最大トークン数が異常です: {$model->maxTokens}";
            }

            if ($model->contextWindow && $model->contextWindow < 1) {
                $issues[] = "モデル '{$model->id}' のコンテキストウィンドウサイズが異常です: {$model->contextWindow}";
            }

            // 必須フィールドのチェック
            if (empty($model->id)) {
                $issues[] = "モデルIDが空です";
            }

            if (empty($model->provider)) {
                $issues[] = "プロバイダーが指定されていません: {$model->id}";
            }
        }

        return array_unique($issues);
    }
}
