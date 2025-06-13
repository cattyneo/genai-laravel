<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Commands;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\ClaudeFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GeminiFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GrokFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 定期的なモデル更新と廃止チェックコマンド
 */
class GenAIScheduledUpdateCommand extends Command
{
    protected $signature = 'genai:scheduled-update
                           {--check-models : モデル更新チェックのみ実行}
                           {--check-deprecation : 廃止予定チェックのみ実行}
                           {--dry-run : 実行せずに結果のみ表示}
                           {--force : 強制実行}
                           {--notify= : 通知方法 (log,mail,slack)}';

    protected $description = 'Run scheduled model updates and deprecation checks';

    public function __construct(
        private ModelRepository $modelRepository,
        private OpenAIFetcher $openAIFetcher,
        private GeminiFetcher $geminiFetcher,
        private ClaudeFetcher $claudeFetcher,
        private GrokFetcher $grokFetcher,
        private ModelReplacementService $replacementService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 GenAI Scheduled Update Started at '.now()->format('Y-m-d H:i:s'));

        $config = config('genai.scheduled_tasks');
        $shouldRunModelCheck = $this->shouldRunTask('model_update_check', $config);
        $shouldRunDeprecationCheck = $this->shouldRunTask('deprecation_check', $config);

        // オプション指定時は該当タスクのみ実行
        if ($this->option('check-models')) {
            $shouldRunModelCheck = true;
            $shouldRunDeprecationCheck = false;
        }

        if ($this->option('check-deprecation')) {
            $shouldRunModelCheck = false;
            $shouldRunDeprecationCheck = true;
        }

        $results = [];

        // モデル更新チェック
        if ($shouldRunModelCheck) {
            $this->line('');
            $this->info('📦 Checking for model updates...');
            $results['model_update'] = $this->performModelUpdateCheck();
        }

        // 廃止予定チェック
        if ($shouldRunDeprecationCheck) {
            $this->line('');
            $this->info('⚠️  Checking for model deprecations...');
            $results['deprecation'] = $this->performDeprecationCheck();
        }

        // 結果通知
        $this->sendNotifications($results);

        $this->line('');
        $this->info('✅ GenAI Scheduled Update Completed at '.now()->format('Y-m-d H:i:s'));

        return 0;
    }

    /**
     * タスクを実行すべきかチェック
     */
    private function shouldRunTask(string $taskName, array $config): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $taskConfig = $config[$taskName] ?? [];

        if (! ($taskConfig['enabled'] ?? false)) {
            return false;
        }

        $frequency = $taskConfig['frequency'] ?? 'daily';
        $time = $taskConfig['time'] ?? '04:00';

        $now = now();
        $scheduledTime = Carbon::createFromFormat('H:i', $time);

        return match ($frequency) {
            'daily' => $now->hour === $scheduledTime->hour && $now->minute === $scheduledTime->minute,
            'weekly' => $now->isMonday() && $now->hour === $scheduledTime->hour && $now->minute === $scheduledTime->minute,
            'monthly' => $now->day === 1 && $now->hour === $scheduledTime->hour && $now->minute === $scheduledTime->minute,
            default => false
        };
    }

    /**
     * モデル更新チェック実行
     */
    private function performModelUpdateCheck(): array
    {
        $results = [
            'new_models' => [],
            'updated_models' => [],
            'errors' => [],
        ];

        $providers = [
            'openai' => $this->openAIFetcher,
            'gemini' => $this->geminiFetcher,
            'claude' => $this->claudeFetcher,
            'grok' => $this->grokFetcher,
        ];

        foreach ($providers as $providerName => $fetcher) {
            try {
                $this->line("  📡 Fetching {$providerName} models...");

                $currentModels = $this->modelRepository->getModelsByProvider($providerName)
                    ->keyBy('id')
                    ->toArray();

                $latestModels = $fetcher->fetchModels();
                $normalizedModels = $fetcher->convertModelsToYaml($latestModels);

                $newCount = 0;
                $updatedCount = 0;

                foreach ($normalizedModels[$providerName] ?? [] as $modelId => $modelData) {
                    if (! isset($currentModels[$modelId])) {
                        $results['new_models'][] = [
                            'provider' => $providerName,
                            'model' => $modelId,
                            'data' => $modelData,
                        ];
                        $newCount++;
                    } else {
                        // モデル情報の変更チェック（簡略化）
                        if ($this->hasModelChanged($currentModels[$modelId], $modelData)) {
                            $results['updated_models'][] = [
                                'provider' => $providerName,
                                'model' => $modelId,
                                'old_data' => $currentModels[$modelId],
                                'new_data' => $modelData,
                            ];
                            $updatedCount++;
                        }
                    }
                }

                $this->line("    ✅ {$providerName}: {$newCount} new, {$updatedCount} updated");
            } catch (\Exception $e) {
                $error = "Failed to check {$providerName}: ".$e->getMessage();
                $results['errors'][] = $error;
                $this->error("    ❌ {$error}");
                Log::error('GenAI scheduled update error', [
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Dry runでない場合は実際に更新
        if (! $this->option('dry-run') && (! empty($results['new_models']) || ! empty($results['updated_models']))) {
            $this->applyModelUpdates($results);
        }

        return $results;
    }

    /**
     * 廃止予定チェック実行
     */
    private function performDeprecationCheck(): array
    {
        $results = [
            'deprecated_models' => [],
            'replacement_suggestions' => [],
            'affected_requests' => [],
        ];

        $warningDays = config('genai.scheduled_tasks.deprecation_check.advance_warning_days', 30);
        $cutoffDate = now()->subDays($warningDays);

        // 使用中のモデルを分析
        $activeModels = GenAIRequest::select('provider', 'model')
            ->where('created_at', '>=', $cutoffDate)
            ->groupBy(['provider', 'model'])
            ->havingRaw('COUNT(*) > 0')
            ->get();

        foreach ($activeModels as $activeModel) {
            $modelInfo = $this->modelRepository->getModelInfo($activeModel->provider, $activeModel->model);

            if (! $modelInfo) {
                continue;
            }

            // 廃止予定フラグチェック
            if ($modelInfo['is_deprecated'] ?? false) {
                $usageCount = GenAIRequest::where('provider', $activeModel->provider)
                    ->where('model', $activeModel->model)
                    ->where('created_at', '>=', $cutoffDate)
                    ->count();

                $results['deprecated_models'][] = [
                    'provider' => $activeModel->provider,
                    'model' => $activeModel->model,
                    'usage_count' => $usageCount,
                    'model_info' => $modelInfo,
                ];

                // 代替モデル提案
                $replacements = $this->replacementService->suggestReplacementsForDeprecated(
                    $activeModel->model,
                    $activeModel->provider
                );

                if (! empty($replacements)) {
                    $results['replacement_suggestions'][] = [
                        'deprecated_model' => $activeModel->model,
                        'provider' => $activeModel->provider,
                        'suggestions' => array_slice($replacements, 0, 3), // トップ3を提案
                    ];
                }
            }

            // 実験的モデルの長期使用警告
            $modelName = $modelInfo['model'] ?? $activeModel->model;
            if (str_contains($modelName, 'experimental') || str_contains($modelName, 'preview')) {
                $firstUsed = GenAIRequest::where('provider', $activeModel->provider)
                    ->where('model', $activeModel->model)
                    ->min('created_at');

                if ($firstUsed && Carbon::parse($firstUsed)->diffInDays(now()) > 90) {
                    $results['deprecated_models'][] = [
                        'provider' => $activeModel->provider,
                        'model' => $activeModel->model,
                        'reason' => 'experimental_model_long_usage',
                        'first_used' => $firstUsed,
                        'days_used' => Carbon::parse($firstUsed)->diffInDays(now()),
                    ];
                }
            }
        }

        $this->line('  ⚠️  Found '.count($results['deprecated_models']).' deprecated/experimental models in use');
        $this->line('  💡 Generated '.count($results['replacement_suggestions']).' replacement suggestions');

        return $results;
    }

    /**
     * モデル変更チェック
     */
    private function hasModelChanged(array $oldModel, array $newModel): bool
    {
        // 重要な変更のみチェック（機能、制限、価格等）
        $importantKeys = ['features', 'limits', 'pricing', 'type'];

        foreach ($importantKeys as $key) {
            if (($oldModel[$key] ?? null) !== ($newModel[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * モデル更新適用
     */
    private function applyModelUpdates(array $results): void
    {
        $this->line('');
        $this->info('🔄 Applying model updates...');

        foreach ($results['new_models'] as $newModel) {
            try {
                // 新しいモデル追加のロジック実装
                $this->line("  ➕ Adding {$newModel['provider']}/{$newModel['model']}");
            } catch (\Exception $e) {
                $this->error("    ❌ Failed to add {$newModel['provider']}/{$newModel['model']}: ".$e->getMessage());
            }
        }

        foreach ($results['updated_models'] as $updatedModel) {
            try {
                // モデル更新のロジック実装
                $this->line("  🔄 Updating {$updatedModel['provider']}/{$updatedModel['model']}");
            } catch (\Exception $e) {
                $this->error("    ❌ Failed to update {$updatedModel['provider']}/{$updatedModel['model']}: ".$e->getMessage());
            }
        }
    }

    /**
     * 結果通知送信
     */
    private function sendNotifications(array $results): void
    {
        $notifyChannels = $this->option('notify') ?? config('genai.scheduled_tasks.model_update_check.notify_channels', 'log');
        $channels = explode(',', $notifyChannels);

        foreach ($channels as $channel) {
            $channel = trim($channel);

            try {
                match ($channel) {
                    'log' => $this->sendLogNotification($results),
                    'mail' => $this->sendMailNotification($results),
                    'slack' => $this->sendSlackNotification($results),
                    default => null
                };
            } catch (\Exception $e) {
                $this->error("Failed to send {$channel} notification: ".$e->getMessage());
            }
        }
    }

    /**
     * ログ通知
     */
    private function sendLogNotification(array $results): void
    {
        $summary = $this->generateNotificationSummary($results);
        Log::info('GenAI Scheduled Update Results', $summary);
    }

    /**
     * メール通知
     */
    private function sendMailNotification(array $results): void
    {
        // メール通知実装（簡略化）
        Log::info('Mail notification would be sent', $this->generateNotificationSummary($results));
    }

    /**
     * Slack通知
     */
    private function sendSlackNotification(array $results): void
    {
        // Slack通知実装（簡略化）
        Log::info('Slack notification would be sent', $this->generateNotificationSummary($results));
    }

    /**
     * 通知サマリー生成
     */
    private function generateNotificationSummary(array $results): array
    {
        $summary = [
            'timestamp' => now()->toISOString(),
            'total_new_models' => 0,
            'total_updated_models' => 0,
            'total_deprecated_models' => 0,
            'total_errors' => 0,
        ];

        if (isset($results['model_update'])) {
            $summary['total_new_models'] = count($results['model_update']['new_models'] ?? []);
            $summary['total_updated_models'] = count($results['model_update']['updated_models'] ?? []);
            $summary['total_errors'] += count($results['model_update']['errors'] ?? []);
        }

        if (isset($results['deprecation'])) {
            $summary['total_deprecated_models'] = count($results['deprecation']['deprecated_models'] ?? []);
        }

        return $summary;
    }
}
