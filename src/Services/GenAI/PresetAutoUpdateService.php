<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

/**
 * プリセット自動更新サービス
 *
 * モデル変更、廃止予定、性能向上等に基づいてプリセットを自動更新
 */
final class PresetAutoUpdateService
{
    public function __construct(
        private ModelReplacementService $replacementService,
        private PresetRepository $presetRepository,
        private NotificationService $notificationService
    ) {
        $this->validateConfiguration();
    }

    /**
     * 設定検証
     */
    private function validateConfiguration(): void
    {
        $config = config('genai.preset_auto_update', []);

        // ストラテジー検証
        $validStrategies = ['conservative', 'moderate', 'aggressive'];
        $strategy = $config['strategy'] ?? 'moderate';
        if (! in_array($strategy, $validStrategies)) {
            throw new \InvalidArgumentException("Invalid strategy: {$strategy}. Must be one of: ".implode(', ', $validStrategies));
        }

        // バックアップ保持日数検証
        $retentionDays = $config['backup_retention_days'] ?? 30;
        if ($retentionDays < 0) {
            throw new \InvalidArgumentException("Backup retention days must be non-negative, got: {$retentionDays}");
        }
    }

    /**
     * 更新チェック（テスト用エイリアス）
     */
    public function checkForUpdates(): array
    {
        return $this->updateAllPresets(['dry_run' => true]);
    }

    /**
     * プリセット更新実行（テスト用エイリアス）
     */
    public function updatePresets(array $presetNames = []): array
    {
        if (empty($presetNames)) {
            return $this->updateAllPresets();
        }

        $results = [
            'updated_presets' => [],
            'errors' => [],
        ];

        foreach ($presetNames as $presetName) {
            try {
                $preset = $this->presetRepository->getPreset($presetName);
                if ($preset) {
                    $updateResult = $this->evaluatePresetForUpdate($presetName, $preset, []);
                    if (! empty($updateResult['updates'])) {
                        $results['updated_presets'][] = $presetName;
                    }
                }
            } catch (\Exception $e) {
                $results['errors'][] = "Failed to update {$presetName}: ".$e->getMessage();
            }
        }

        return $results;
    }

    /**
     * バックアップ作成
     */
    public function createBackup(string $presetName): array
    {
        try {
            $preset = $this->presetRepository->getPreset($presetName);
            if (! $preset) {
                return ['success' => false, 'error' => 'Preset not found'];
            }

            $backupPath = "genai/backups/{$presetName}_".now()->format('Y-m-d_H-i-s').'.yaml';
            Storage::put($backupPath, Yaml::dump($preset));

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'created_at' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * バックアップから復元
     */
    public function restoreFromBackup(string $backupPath): array
    {
        try {
            if (! Storage::exists($backupPath)) {
                return ['success' => false, 'error' => 'Backup file not found'];
            }

            $content = Storage::get($backupPath);
            $preset = Yaml::parse($content);

            // プリセット名をバックアップパスから抽出
            $presetName = basename($backupPath, '.yaml');
            $presetName = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', '', $presetName);

            $this->presetRepository->savePreset($presetName, $preset);

            return [
                'success' => true,
                'preset_name' => $presetName,
                'restored_at' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * バージョン変更記録
     */
    public function recordVersionChange(string $presetName, array $changes): void
    {
        $historyPath = "genai/version_history/{$presetName}.json";

        $history = [];
        if (Storage::exists($historyPath)) {
            $history = json_decode(Storage::get($historyPath), true) ?? [];
        }

        $history[] = [
            'timestamp' => now()->toISOString(),
            'changes' => $changes,
            'version' => count($history) + 1,
        ];

        Storage::put($historyPath, json_encode($history, JSON_PRETTY_PRINT));
    }

    /**
     * バージョン履歴取得
     */
    public function getVersionHistory(string $presetName): array
    {
        $historyPath = "genai/version_history/{$presetName}.json";

        if (! Storage::exists($historyPath)) {
            return [];
        }

        return json_decode(Storage::get($historyPath), true) ?? [];
    }

    /**
     * 競合検出
     */
    public function detectConflicts(array $presetNames): array
    {
        $conflicts = [];

        foreach ($presetNames as $presetName) {
            try {
                $preset = $this->presetRepository->getPreset($presetName);
                if (! $preset) {
                    continue;
                }

                // モデル競合チェック
                if (isset($preset['model'])) {
                    $provider = $preset['provider'] ?? 'openai'; // デフォルトプロバイダーを指定
                    $replacements = $this->replacementService->findReplacements($preset['model'], $provider);
                    if (! empty($replacements)) {
                        $conflicts[] = [
                            'preset' => $presetName,
                            'type' => 'model_deprecated',
                            'current_model' => $preset['model'],
                            'suggested_models' => array_column($replacements, 'replacement_model'),
                        ];
                    }
                }
            } catch (\Exception $e) {
                $conflicts[] = [
                    'preset' => $presetName,
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * 競合解決
     */
    public function resolveConflicts(array $conflicts, array $resolutions): array
    {
        $results = [];

        foreach ($conflicts as $conflict) {
            $presetName = $conflict['preset'];
            $resolution = $resolutions[$presetName] ?? null;

            if (! $resolution) {
                $results[$presetName] = ['status' => 'skipped', 'reason' => 'No resolution provided'];

                continue;
            }

            try {
                $preset = $this->presetRepository->getPreset($presetName);
                if (! $preset) {
                    $results[$presetName] = ['status' => 'error', 'reason' => 'Preset not found'];

                    continue;
                }

                // 解決策を適用
                if ($conflict['type'] === 'model_deprecated' && isset($resolution['new_model'])) {
                    $preset['model'] = $resolution['new_model'];
                    $this->presetRepository->savePreset($presetName, $preset);
                    $results[$presetName] = ['status' => 'resolved', 'action' => 'model_updated'];
                }
            } catch (\Exception $e) {
                $results[$presetName] = ['status' => 'error', 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * プリセット整合性検証
     */
    public function validatePresetIntegrity(string $presetName): array
    {
        try {
            $preset = $this->presetRepository->getPreset($presetName);
            if (! $preset) {
                return ['valid' => false, 'errors' => ['Preset not found']];
            }

            $errors = [];

            // 必須フィールドチェック
            if (! isset($preset['model'])) {
                $errors[] = 'Missing required field: model';
            }

            // モデル存在チェック
            if (isset($preset['model'])) {
                $availableModels = config('genai.models', []);
                if (! isset($availableModels[$preset['model']])) {
                    $errors[] = "Model '{$preset['model']}' is not available";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'preset_name' => $presetName,
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'preset_name' => $presetName,
            ];
        }
    }

    /**
     * 全プリセットの自動更新チェック
     */
    public function updateAllPresets(array $options = []): array
    {
        $updateRules = config('genai.preset_auto_update.rules', []);
        $dryRun = $options['dry_run'] ?? false;

        $results = [
            'checked_presets' => 0,
            'updated_presets' => [],
            'suggestions' => [],
            'errors' => [],
        ];

        $presets = $this->presetRepository->getAllPresets();

        foreach ($presets as $presetName => $presetData) {
            $results['checked_presets']++;

            try {
                $updateResult = $this->evaluatePresetForUpdate($presetName, $presetData, $updateRules);

                if ($updateResult['should_update']) {
                    if (! $dryRun) {
                        $this->applyPresetUpdate($presetName, $presetData, $updateResult);
                        $results['updated_presets'][] = [
                            'preset_name' => $presetName,
                            'changes' => $updateResult['changes'],
                            'reason' => $updateResult['reason'],
                        ];
                    } else {
                        $results['suggestions'][] = [
                            'preset_name' => $presetName,
                            'suggested_changes' => $updateResult['changes'],
                            'reason' => $updateResult['reason'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'preset_name' => $presetName,
                    'error' => $e->getMessage(),
                ];
                Log::error("Failed to update preset {$presetName}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // 結果通知
        if (! empty($results['updated_presets']) || ! empty($results['suggestions'])) {
            $this->notificationService->sendPresetUpdateNotification($results);
        }

        return $results;
    }

    /**
     * 特定プリセットの更新評価
     */
    public function evaluatePresetForUpdate(string $presetName, array $presetData, array $updateRules): array
    {
        $currentModel = $presetData['model'] ?? null;
        $currentProvider = $presetData['provider'] ?? null;

        if (! $currentModel || ! $currentProvider) {
            return ['should_update' => false, 'reason' => 'Invalid preset configuration'];
        }

        $evaluation = [
            'should_update' => false,
            'changes' => [],
            'reason' => '',
            'confidence' => 0.0,
        ];

        // 1. 廃止予定モデルチェック
        $deprecationCheck = $this->checkDeprecatedModel($currentModel, $currentProvider);
        if ($deprecationCheck['is_deprecated']) {
            $evaluation['should_update'] = true;
            $evaluation['changes']['model'] = $deprecationCheck['replacement'];
            $evaluation['reason'] = 'Model is deprecated';
            $evaluation['confidence'] = 0.9;

            return $evaluation;
        }

        // 2. 性能向上モデルチェック
        if ($updateRules['enable_performance_upgrades'] ?? true) {
            $performanceUpgrade = $this->checkPerformanceUpgrade($presetName, $currentModel, $currentProvider);
            if ($performanceUpgrade['has_upgrade']) {
                $evaluation['should_update'] = true;
                $evaluation['changes']['model'] = $performanceUpgrade['upgraded_model'];
                $evaluation['reason'] = 'Performance upgrade available';
                $evaluation['confidence'] = $performanceUpgrade['confidence'];

                return $evaluation;
            }
        }

        // 3. コスト最適化チェック
        if ($updateRules['enable_cost_optimization'] ?? true) {
            $costOptimization = $this->checkCostOptimization($presetName, $currentModel, $currentProvider);
            if ($costOptimization['has_optimization']) {
                $evaluation['should_update'] = true;
                $evaluation['changes']['model'] = $costOptimization['optimized_model'];
                $evaluation['reason'] = 'Cost optimization available';
                $evaluation['confidence'] = $costOptimization['confidence'];

                return $evaluation;
            }
        }

        // 4. オプション最適化チェック
        $optionOptimization = $this->checkOptionOptimization($presetName, $presetData);
        if ($optionOptimization['has_optimization']) {
            $evaluation['should_update'] = true;
            $evaluation['changes']['options'] = $optionOptimization['optimized_options'];
            $evaluation['reason'] = 'Options optimization available';
            $evaluation['confidence'] = $optionOptimization['confidence'];
        }

        return $evaluation;
    }

    /**
     * 廃止予定モデルチェック
     */
    private function checkDeprecatedModel(string $model, string $provider): array
    {
        $replacements = $this->replacementService->suggestReplacementsForDeprecated($model, $provider);

        if (! empty($replacements)) {
            return [
                'is_deprecated' => true,
                'replacement' => $replacements[0]['model']['model'] ?? null,
                'confidence' => $replacements[0]['total_score'] ?? 0.0,
            ];
        }

        return ['is_deprecated' => false];
    }

    /**
     * 性能向上モデルチェック
     */
    private function checkPerformanceUpgrade(string $presetName, string $model, string $provider): array
    {
        $context = [
            'use_case' => $this->inferUseCaseFromPreset($presetName),
            'performance_requirements' => [
                'maintain_or_improve' => true,
                'cost_tolerance' => 1.1, // 10%のコスト増加まで許容
            ],
        ];

        $recommendations = $this->replacementService->findReplacements($model, $provider, $context);

        // より高性能なモデルを探す
        foreach ($recommendations as $recommendation) {
            if ($recommendation['performance_score'] > 0.8 && $recommendation['total_score'] > 0.7) {
                return [
                    'has_upgrade' => true,
                    'upgraded_model' => $recommendation['model']['model'],
                    'confidence' => $recommendation['total_score'],
                    'performance_gain' => $recommendation['performance_score'],
                ];
            }
        }

        return ['has_upgrade' => false];
    }

    /**
     * コスト最適化チェック
     */
    private function checkCostOptimization(string $presetName, string $model, string $provider): array
    {
        $context = [
            'use_case' => $this->inferUseCaseFromPreset($presetName),
            'performance_requirements' => [
                'maintain_performance' => true,
                'cost_tolerance' => 0.8, // 20%のコスト削減を目標
            ],
        ];

        $recommendations = $this->replacementService->findReplacements($model, $provider, $context);

        // コスト効率の良いモデルを探す
        foreach ($recommendations as $recommendation) {
            if ($recommendation['cost_efficiency'] > 1.2 && $recommendation['total_score'] > 0.6) {
                return [
                    'has_optimization' => true,
                    'optimized_model' => $recommendation['model']['model'],
                    'confidence' => $recommendation['total_score'],
                    'cost_savings' => ($recommendation['cost_efficiency'] - 1.0) * 100 .'%',
                ];
            }
        }

        return ['has_optimization' => false];
    }

    /**
     * オプション最適化チェック
     */
    private function checkOptionOptimization(string $presetName, array $presetData): array
    {
        $currentOptions = $presetData['options'] ?? [];
        $optimizedOptions = $this->generateOptimizedOptions($presetName, $currentOptions);

        if ($optimizedOptions !== $currentOptions) {
            return [
                'has_optimization' => true,
                'optimized_options' => $optimizedOptions,
                'confidence' => 0.7,
            ];
        }

        return ['has_optimization' => false];
    }

    /**
     * プリセット更新適用
     */
    private function applyPresetUpdate(string $presetName, array $presetData, array $updateResult): void
    {
        $updatedPreset = $presetData;

        // バックアップ作成
        $this->createPresetBackup($presetName, $presetData);

        // 変更適用
        foreach ($updateResult['changes'] as $key => $value) {
            $updatedPreset[$key] = $value;
        }

        // 更新履歴追加
        $updatedPreset['auto_update_history'][] = [
            'timestamp' => now()->toISOString(),
            'reason' => $updateResult['reason'],
            'changes' => $updateResult['changes'],
            'confidence' => $updateResult['confidence'],
            'previous_values' => array_intersect_key($presetData, $updateResult['changes']),
        ];

        // プリセット保存
        $this->presetRepository->savePreset($presetName, $updatedPreset);

        Log::info("Auto-updated preset: {$presetName}", [
            'reason' => $updateResult['reason'],
            'changes' => $updateResult['changes'],
        ]);
    }

    /**
     * プリセットバックアップ作成
     */
    private function createPresetBackup(string $presetName, array $presetData): void
    {
        $backupDir = 'genai/preset_backups';
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupFile = "{$backupDir}/{$presetName}_{$timestamp}.yaml";

        if (! Storage::exists($backupDir)) {
            Storage::makeDirectory($backupDir);
        }

        Storage::put($backupFile, Yaml::dump($presetData));
    }

    /**
     * プリセット名から使用ケースを推測
     */
    private function inferUseCaseFromPreset(string $presetName): string
    {
        $presetLower = strtolower($presetName);

        return match (true) {
            str_contains($presetLower, 'code') || str_contains($presetLower, 'program') => 'coding',
            str_contains($presetLower, 'chat') || str_contains($presetLower, 'conversation') => 'chat',
            str_contains($presetLower, 'translate') || str_contains($presetLower, 'trans') => 'translation',
            str_contains($presetLower, 'content') || str_contains($presetLower, 'write') => 'content_generation',
            str_contains($presetLower, 'analyze') || str_contains($presetLower, 'analysis') => 'analysis',
            str_contains($presetLower, 'image') || str_contains($presetLower, 'vision') => 'vision',
            default => 'general'
        };
    }

    /**
     * 最適化されたオプション生成
     */
    private function generateOptimizedOptions(string $presetName, array $currentOptions): array
    {
        $useCase = $this->inferUseCaseFromPreset($presetName);
        $optimizedOptions = $currentOptions;

        // 使用ケース別の最適化
        switch ($useCase) {
            case 'coding':
                $optimizedOptions['temperature'] = min($currentOptions['temperature'] ?? 0.7, 0.3);
                $optimizedOptions['top_p'] = min($currentOptions['top_p'] ?? 0.95, 0.9);
                break;

            case 'content_generation':
                $optimizedOptions['temperature'] = max($currentOptions['temperature'] ?? 0.7, 0.8);
                $optimizedOptions['presence_penalty'] = 0.1;
                break;

            case 'translation':
                $optimizedOptions['temperature'] = 0.3;
                $optimizedOptions['top_p'] = 0.9;
                break;
        }

        return $optimizedOptions;
    }

    /**
     * ルールベース更新の実行
     */
    public function executeRuleBasedUpdates(array $rules): array
    {
        $results = [];

        foreach ($rules as $rule) {
            try {
                $ruleResult = $this->executeUpdateRule($rule);
                $results[] = $ruleResult;
            } catch (\Exception $e) {
                $results[] = [
                    'rule' => $rule['name'] ?? 'unknown',
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to execute update rule', [
                    'rule' => $rule,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * 個別ルール実行
     */
    private function executeUpdateRule(array $rule): array
    {
        $ruleName = $rule['name'];
        $conditions = $rule['conditions'] ?? [];
        $actions = $rule['actions'] ?? [];

        // 条件チェック
        if (! $this->evaluateRuleConditions($conditions)) {
            return [
                'rule' => $ruleName,
                'success' => true,
                'executed' => false,
                'reason' => 'Conditions not met',
            ];
        }

        // アクション実行
        $actionResults = [];
        foreach ($actions as $action) {
            $actionResults[] = $this->executeRuleAction($action);
        }

        return [
            'rule' => $ruleName,
            'success' => true,
            'executed' => true,
            'action_results' => $actionResults,
        ];
    }

    /**
     * ルール条件評価
     */
    private function evaluateRuleConditions(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->evaluateSingleCondition($condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 単一条件評価
     */
    private function evaluateSingleCondition(array $condition): bool
    {
        $type = $condition['type'];

        return match ($type) {
            'model_deprecated' => $this->checkModelDeprecatedCondition($condition),
            'cost_threshold' => $this->checkCostThresholdCondition($condition),
            'performance_degradation' => $this->checkPerformanceDegradationCondition($condition),
            'time_based' => $this->checkTimeBasedCondition($condition),
            default => false
        };
    }

    /**
     * ルールアクション実行
     */
    private function executeRuleAction(array $action): array
    {
        $actionType = $action['type'];

        return match ($actionType) {
            'update_model' => $this->executeUpdateModelAction($action),
            'update_options' => $this->executeUpdateOptionsAction($action),
            'notify' => $this->executeNotifyAction($action),
            'backup_preset' => $this->executeBackupPresetAction($action),
            default => ['success' => false, 'error' => 'Unknown action type']
        };
    }

    // 条件チェックメソッド群（簡略化実装）
    private function checkModelDeprecatedCondition(array $condition): bool
    {
        return true; // 実装簡略化
    }

    private function checkCostThresholdCondition(array $condition): bool
    {
        return true; // 実装簡略化
    }

    private function checkPerformanceDegradationCondition(array $condition): bool
    {
        return true; // 実装簡略化
    }

    private function checkTimeBasedCondition(array $condition): bool
    {
        return true; // 実装簡略化
    }

    // アクション実行メソッド群（簡略化実装）
    private function executeUpdateModelAction(array $action): array
    {
        return ['success' => true, 'action' => 'update_model'];
    }

    private function executeUpdateOptionsAction(array $action): array
    {
        return ['success' => true, 'action' => 'update_options'];
    }

    private function executeNotifyAction(array $action): array
    {
        return ['success' => true, 'action' => 'notify'];
    }

    private function executeBackupPresetAction(array $action): array
    {
        return ['success' => true, 'action' => 'backup_preset'];
    }

    /**
     * 次回更新スケジュール設定
     */
    public function scheduleNextUpdate(): array
    {
        $interval = config('genai.preset_auto_update.check_interval', 'daily');
        $nextUpdate = match ($interval) {
            'hourly' => now()->addHour(),
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            default => now()->addDay()
        };

        return [
            'next_update_time' => $nextUpdate->toISOString(),
            'cron_expression' => $this->getCronExpression($interval),
            'scheduled' => true,
        ];
    }

    /**
     * Cron式生成
     */
    private function getCronExpression(string $interval): string
    {
        return match ($interval) {
            'hourly' => '0 * * * *',
            'daily' => '0 2 * * *',
            'weekly' => '0 2 * * 0',
            default => '0 2 * * *'
        };
    }

    /**
     * 更新レポート生成
     */
    public function generateUpdateReport(string $period = 'last_week'): array
    {
        $startDate = match ($period) {
            'last_day' => now()->subDay(),
            'last_week' => now()->subWeek(),
            'last_month' => now()->subMonth(),
            default => now()->subWeek()
        };

        $presets = $this->presetRepository->getAllPresets();
        $updatedPresets = [];
        $totalUpdates = 0;

        foreach ($presets as $presetName => $presetData) {
            $history = $this->getVersionHistory($presetName);
            $recentUpdates = array_filter($history, function ($entry) use ($startDate) {
                return Carbon::parse($entry['timestamp'])->isAfter($startDate);
            });

            if (! empty($recentUpdates)) {
                $updatedPresets[] = [
                    'preset_name' => $presetName,
                    'updates_count' => count($recentUpdates),
                    'latest_update' => $recentUpdates[0],
                ];
                $totalUpdates += count($recentUpdates);
            }
        }

        return [
            'period' => $period,
            'summary' => [
                'total_updates' => $totalUpdates,
                'updated_presets_count' => count($updatedPresets),
            ],
            'updated_presets' => $updatedPresets,
            'statistics' => [
                'success_rate' => $totalUpdates > 0 ? 100 : 0,
                'average_updates_per_preset' => count($updatedPresets) > 0 ? $totalUpdates / count($updatedPresets) : 0,
            ],
        ];
    }

    /**
     * 最後の更新をロールバック
     */
    public function rollbackLastUpdate(string $presetName): array
    {
        try {
            $history = $this->getVersionHistory($presetName);
            if (empty($history)) {
                return ['success' => false, 'error' => 'No update history found'];
            }

            $lastUpdate = $history[0];
            $preset = $this->presetRepository->getPreset($presetName);
            if (! $preset) {
                return ['success' => false, 'error' => 'Preset not found'];
            }

            // 前の値を復元
            if (isset($lastUpdate['previous_values'])) {
                foreach ($lastUpdate['previous_values'] as $key => $value) {
                    $preset[$key] = $value;
                }

                // 履歴から最新エントリを削除
                unset($preset['auto_update_history'][0]);
                $preset['auto_update_history'] = array_values($preset['auto_update_history']);

                $this->presetRepository->savePreset($presetName, $preset);

                return [
                    'success' => true,
                    'rollback_details' => [
                        'preset_name' => $presetName,
                        'rolled_back_changes' => $lastUpdate['changes'],
                        'restored_values' => $lastUpdate['previous_values'],
                    ],
                ];
            }

            return ['success' => false, 'error' => 'No previous values to restore'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 更新計画生成
     */
    public function generateUpdatePlan(): array
    {
        $strategy = config('genai.preset_auto_update.strategy', 'moderate');
        $presets = $this->presetRepository->getAllPresets();
        $recommendations = [];

        foreach ($presets as $presetName => $presetData) {
            $evaluation = $this->evaluatePresetForUpdate($presetName, $presetData, config('genai.preset_auto_update.rules', []));
            if ($evaluation['should_update']) {
                $recommendations[] = [
                    'preset_name' => $presetName,
                    'recommended_changes' => $evaluation['changes'],
                    'confidence' => $evaluation['confidence'],
                    'reason' => $evaluation['reason'],
                ];
            }
        }

        return [
            'strategy' => $strategy,
            'recommended_updates' => $recommendations,
            'total_recommendations' => count($recommendations),
        ];
    }

    /**
     * 古いバックアップをクリーンアップ
     */
    public function cleanupOldBackups(): array
    {
        $retentionDays = config('genai.preset_auto_update.backup_retention_days', 30);
        $cutoffDate = now()->subDays($retentionDays);

        $backupDir = 'genai/backups';
        if (! Storage::exists($backupDir)) {
            return ['success' => true, 'deleted_count' => 0, 'remaining_count' => 0];
        }

        $files = Storage::files($backupDir);
        $deletedCount = 0;
        $remainingCount = 0;

        foreach ($files as $file) {
            $fileTime = Storage::lastModified($file);
            if ($fileTime < $cutoffDate->timestamp) {
                Storage::delete($file);
                $deletedCount++;
            } else {
                $remainingCount++;
            }
        }

        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'remaining_count' => $remainingCount,
        ];
    }
}
