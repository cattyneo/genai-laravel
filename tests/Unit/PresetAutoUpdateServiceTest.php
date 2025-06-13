<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetAutoUpdateService;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetRepository;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery as m;
use Symfony\Component\Yaml\Yaml;

class PresetAutoUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private PresetAutoUpdateService $autoUpdateService;

    private array $mockConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConfig = [
            'enabled' => true,
            'check_interval' => 'daily',
            'backup_enabled' => true,
            'rules' => [
                'enable_performance_upgrades' => true,
                'enable_cost_optimization' => true,
                'enable_deprecation_updates' => true,
            ],
        ];

        config(['genai.preset_auto_update' => $this->mockConfig]);

        // 依存関係のモックを作成
        $mockReplacementService = m::mock(ModelReplacementService::class);
        $mockPresetRepository = m::mock(PresetRepository::class);
        $mockNotificationService = m::mock(NotificationService::class);

        // デフォルトの期待値を設定
        $mockReplacementService->shouldReceive('suggestReplacementsForDeprecated')->andReturn([]);
        $mockReplacementService->shouldReceive('findReplacements')->andReturn([]);
        $mockPresetRepository->shouldReceive('getAllPresets')->andReturn([]);
        $mockNotificationService->shouldReceive('sendPresetUpdateNotification')->andReturn(true);

        $this->autoUpdateService = new PresetAutoUpdateService(
            $mockReplacementService,
            $mockPresetRepository,
            $mockNotificationService
        );
        $this->createTestPresets();
    }

    public function test_can_check_for_updates()
    {
        $updates = $this->autoUpdateService->checkForUpdates();

        $this->assertIsArray($updates);
        $this->assertArrayHasKey('checked_presets', $updates);
        $this->assertArrayHasKey('updated_presets', $updates);
        $this->assertArrayHasKey('suggestions', $updates);
        $this->assertArrayHasKey('errors', $updates);
    }

    public function test_can_update_presets()
    {
        // プリセット名の配列を渡す
        $presetNames = ['default', 'summarize'];

        $result = $this->autoUpdateService->updatePresets($presetNames);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('updated_presets', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_can_create_backups()
    {
        $presetName = 'default';
        $backupResult = $this->autoUpdateService->createBackup($presetName);

        $this->assertIsArray($backupResult);
        $this->assertArrayHasKey('success', $backupResult);

        if ($backupResult['success']) {
            $this->assertArrayHasKey('backup_path', $backupResult);
            $this->assertArrayHasKey('created_at', $backupResult);
        }
    }

    public function test_can_restore_from_backup()
    {
        // まずバックアップを作成
        $presetName = 'default';
        $backupResult = $this->autoUpdateService->createBackup($presetName);

        if ($backupResult['success']) {
            $backupPath = $backupResult['backup_path'];

            // バックアップから復元
            $restoreResult = $this->autoUpdateService->restoreFromBackup($backupPath);

            $this->assertIsArray($restoreResult);
            $this->assertArrayHasKey('success', $restoreResult);
        } else {
            $this->markTestSkipped('Backup creation failed');
        }
    }

    public function test_can_track_version_history()
    {
        $presetName = 'default';

        // バージョン履歴を記録（引数を配列で渡す）
        $this->autoUpdateService->recordVersionChange($presetName, [
            'from_version' => '1.0.0',
            'to_version' => '1.1.0',
            'changes' => ['improved prompts'],
            'strategy' => 'moderate',
        ]);

        $history = $this->autoUpdateService->getVersionHistory($presetName);

        $this->assertIsArray($history);
    }

    public function test_can_detect_conflicts()
    {
        // プリセット名の配列を渡す
        $presetNames = ['default', 'custom'];
        $conflicts = $this->autoUpdateService->detectConflicts($presetNames);

        $this->assertIsArray($conflicts);
    }

    public function test_can_resolve_conflicts()
    {
        $conflicts = [
            [
                'preset' => 'custom',
                'type' => 'parameter_mismatch',
                'manual_changes' => ['temperature' => 0.8],
                'upstream_changes' => ['temperature' => 0.7],
            ],
        ];

        $resolutions = [
            'custom' => [
                'new_model' => 'gpt-4o-mini',
                'resolution_strategy' => 'keep_manual',
            ],
        ];

        $result = $this->autoUpdateService->resolveConflicts($conflicts, $resolutions);

        $this->assertIsArray($result);
    }

    public function test_can_validate_preset_integrity()
    {
        $presetName = 'default';
        $validation = $this->autoUpdateService->validatePresetIntegrity($presetName);

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('valid', $validation);
        $this->assertArrayHasKey('errors', $validation);
        $this->assertArrayHasKey('preset_name', $validation);
    }

    public function test_can_schedule_updates()
    {
        Event::fake();

        $schedule = $this->autoUpdateService->scheduleNextUpdate();

        $this->assertIsArray($schedule);
        $this->assertArrayHasKey('next_update_time', $schedule);
        $this->assertArrayHasKey('cron_expression', $schedule);
        $this->assertArrayHasKey('scheduled', $schedule);
        $this->assertTrue($schedule['scheduled']);
    }

    public function test_can_generate_update_report()
    {
        // いくつかの更新を実行
        $this->autoUpdateService->recordVersionChange('default', [
            'from_version' => '1.0.0',
            'to_version' => '1.1.0',
            'changes' => ['prompt improvements'],
        ]);

        $this->autoUpdateService->recordVersionChange('summarize', [
            'from_version' => '2.0.0',
            'to_version' => '2.1.0',
            'changes' => ['new parameters'],
        ]);

        $report = $this->autoUpdateService->generateUpdateReport('last_week');

        $this->assertIsArray($report);
        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('updated_presets', $report);
        $this->assertArrayHasKey('statistics', $report);
    }

    public function test_can_rollback_updates()
    {
        // 更新を実行
        $presetNames = ['default'];

        $updateResult = $this->autoUpdateService->updatePresets($presetNames);
        $this->assertIsArray($updateResult);

        // ロールバック実行
        $rollbackResult = $this->autoUpdateService->rollbackLastUpdate('default');

        $this->assertIsArray($rollbackResult);
        $this->assertArrayHasKey('success', $rollbackResult);
    }

    public function test_handles_different_update_strategies()
    {
        $strategies = ['conservative', 'moderate', 'aggressive'];

        foreach ($strategies as $strategy) {
            config(['genai.preset_auto_update.strategy' => $strategy]);

            // 依存関係を再注入
            $mockReplacementService = \Mockery::mock(ModelReplacementService::class);
            $mockPresetRepository = \Mockery::mock(PresetRepository::class);
            $mockNotificationService = \Mockery::mock(NotificationService::class);

            $service = new PresetAutoUpdateService(
                $mockReplacementService,
                $mockPresetRepository,
                $mockNotificationService
            );

            $mockReplacementService->shouldReceive('suggestReplacementsForDeprecated')->andReturn([]);
            $mockReplacementService->shouldReceive('findReplacements')->andReturn([]);
            $mockPresetRepository->shouldReceive('getAllPresets')->andReturn([]);

            $updatePlan = $service->generateUpdatePlan();

            $this->assertIsArray($updatePlan);
            $this->assertArrayHasKey('strategy', $updatePlan);
            $this->assertEquals($strategy, $updatePlan['strategy']);
            $this->assertArrayHasKey('recommended_updates', $updatePlan);
        }
    }

    public function test_can_cleanup_old_backups()
    {
        // 古いバックアップを作成
        for ($i = 0; $i < 5; $i++) {
            $oldBackupPath = "genai/backups/old_backup_{$i}_".now()->subDays(35)->format('Y-m-d_H-i-s').'.json';
            Storage::put($oldBackupPath, json_encode(['test' => 'data']));
        }

        // 新しいバックアップを作成
        for ($i = 0; $i < 3; $i++) {
            $newBackupPath = "genai/backups/new_backup_{$i}_".now()->subDays(5)->format('Y-m-d_H-i-s').'.json';
            Storage::put($newBackupPath, json_encode(['test' => 'data']));
        }

        $cleanupResult = $this->autoUpdateService->cleanupOldBackups();

        $this->assertIsArray($cleanupResult);
        $this->assertArrayHasKey('success', $cleanupResult);
        $this->assertArrayHasKey('deleted_count', $cleanupResult);
        $this->assertArrayHasKey('remaining_count', $cleanupResult);
    }

    public function test_handles_disabled_auto_update()
    {
        config(['genai.preset_auto_update.enabled' => false]);

        // 依存関係を再注入
        $mockReplacementService = \Mockery::mock(ModelReplacementService::class);
        $mockPresetRepository = \Mockery::mock(PresetRepository::class);
        $mockNotificationService = \Mockery::mock(NotificationService::class);

        $disabledService = new PresetAutoUpdateService(
            $mockReplacementService,
            $mockPresetRepository,
            $mockNotificationService
        );

        $mockReplacementService->shouldReceive('suggestReplacementsForDeprecated')->andReturn([]);
        $mockReplacementService->shouldReceive('findReplacements')->andReturn([]);
        $mockPresetRepository->shouldReceive('getAllPresets')->andReturn([]);

        $result = $disabledService->checkForUpdates();

        $this->assertIsArray($result);
    }

    public function test_validates_update_configuration()
    {
        $this->expectException(\InvalidArgumentException::class);

        // 無効な設定を複数設定してテスト
        config(['genai.preset_auto_update.strategy' => 'invalid_strategy']);
        config(['genai.preset_auto_update.backup_retention_days' => -1]);

        // 依存関係を再注入
        $mockReplacementService = \Mockery::mock(ModelReplacementService::class);
        $mockPresetRepository = \Mockery::mock(PresetRepository::class);
        $mockNotificationService = \Mockery::mock(NotificationService::class);

        new PresetAutoUpdateService(
            $mockReplacementService,
            $mockPresetRepository,
            $mockNotificationService
        );
    }

    private function createTestPresets(): void
    {
        $presets = [
            'default' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'options' => [
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                ],
                'version' => '1.0.0',
            ],
            'summarize' => [
                'provider' => 'claude',
                'model' => 'claude-3-sonnet',
                'options' => [
                    'temperature' => 0.3,
                    'max_tokens' => 500,
                ],
                'version' => '2.0.0',
            ],
        ];

        foreach ($presets as $name => $config) {
            Storage::put("genai/presets/{$name}.yaml", Yaml::dump($config));
        }
    }

    private function modifyTestPreset(): void
    {
        $presetPath = 'genai/presets/default.yaml';
        $preset = Yaml::parse(Storage::get($presetPath));
        $preset['options']['temperature'] = 0.9; // 手動変更
        Storage::put($presetPath, Yaml::dump($preset));
    }

    private function createManuallyModifiedPreset(): void
    {
        $customPreset = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'options' => [
                'temperature' => 0.8, // 手動で変更された値
                'max_tokens' => 1200,
            ],
            'version' => '1.0.0',
            'manually_modified' => true,
            'last_manual_change' => now()->toISOString(),
        ];

        Storage::put('genai/presets/custom.yaml', Yaml::dump($customPreset));
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
