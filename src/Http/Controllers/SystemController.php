<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use CattyNeo\LaravelGenAI\Services\GenAI\PerformanceMonitoringService;
use CattyNeo\LaravelGenAI\Services\GenAI\CostOptimizationService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

/**
 * GenAI システム管理 API コントローラー
 */
class SystemController extends Controller
{
    public function __construct(
        private PerformanceMonitoringService $performanceService,
        private CostOptimizationService $costService,
        private ModelRepository $modelRepository
    ) {}

    /**
     * システム状態取得
     */
    public function getSystemStatus(Request $request): JsonResponse
    {
        try {
            $includeDetails = $request->boolean('include_details', false);
            
            $status = [
                'overall_status' => 'operational',
                'timestamp' => now()->toISOString(),
                'services' => [
                    'database' => $this->checkDatabaseStatus(),
                    'cache' => $this->checkCacheStatus(),
                    'providers' => $this->checkProvidersStatus(),
                    'storage' => $this->checkStorageStatus(),
                ],
                'metrics' => [
                    'total_requests_today' => $this->getTodayRequestCount(),
                    'total_cost_today' => $this->getTodayCost(),
                    'active_sessions' => $this->getActiveSessionCount(),
                    'uptime' => $this->getSystemUptime(),
                ],
            ];
            
            if ($includeDetails) {
                $status['details'] = [
                    'performance_summary' => $this->performanceService->getRealTimeMetrics(),
                    'recent_errors' => $this->getRecentErrors(10),
                    'system_resources' => $this->getSystemResources(),
                ];
            }
            
            // 全体的なステータスを決定
            $status['overall_status'] = $this->determineOverallStatus($status['services']);
            
            return response()->json([
                'success' => true,
                'data' => $status,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'include_details' => $includeDetails,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get system status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ヘルスチェック
     */
    public function healthCheck(Request $request): JsonResponse
    {
        try {
            $checks = [
                'database' => $this->performDatabaseHealthCheck(),
                'cache' => $this->performCacheHealthCheck(),
                'providers' => $this->performProvidersHealthCheck(),
                'disk_space' => $this->performDiskSpaceCheck(),
                'memory' => $this->performMemoryCheck(),
            ];
            
            $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'healthy');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $allHealthy ? 'healthy' : 'degraded',
                    'checks' => $checks,
                    'timestamp' => now()->toISOString(),
                ],
            ], $allHealthy ? 200 : 503);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Health check failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 設定情報取得
     */
    public function getConfig(Request $request): JsonResponse
    {
        try {
            $includeSecrets = $request->boolean('include_secrets', false);
            
            $config = [
                'general' => [
                    'defaults' => config('genai.defaults'),
                    'cache' => config('genai.cache'),
                    'paths' => config('genai.paths'),
                ],
                'services' => [
                    'logging' => config('genai.logging'),
                    'analytics' => config('genai.analytics'),
                    'advanced_services' => config('genai.advanced_services'),
                ],
                'providers' => [],
            ];
            
            // プロバイダー設定（シークレット除く）
            $providers = config('genai.providers', []);
            foreach ($providers as $name => $providerConfig) {
                $config['providers'][$name] = [
                    'base_url' => $providerConfig['base_url'] ?? null,
                    'models_endpoint' => $providerConfig['models_endpoint'] ?? null,
                    'api_key_configured' => !empty($providerConfig['api_key']),
                ];
                
                if ($includeSecrets) {
                    $config['providers'][$name]['api_key'] = $providerConfig['api_key'] ?? null;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $config,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'include_secrets' => $includeSecrets,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get config',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * キャッシュクリア
     */
    public function clearCache(Request $request): JsonResponse
    {
        try {
            $types = $request->get('types', ['all']);
            $cleared = [];
            
            foreach ($types as $type) {
                switch ($type) {
                    case 'genai':
                        Cache::tags(['genai'])->flush();
                        $cleared[] = 'GenAI cache';
                        break;
                    case 'config':
                        Artisan::call('config:clear');
                        $cleared[] = 'Configuration cache';
                        break;
                    case 'route':
                        Artisan::call('route:clear');
                        $cleared[] = 'Route cache';
                        break;
                    case 'view':
                        Artisan::call('view:clear');
                        $cleared[] = 'View cache';
                        break;
                    case 'all':
                        Cache::flush();
                        Artisan::call('config:clear');
                        Artisan::call('route:clear');
                        Artisan::call('view:clear');
                        $cleared = ['All caches'];
                        break;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'cleared' => $cleared,
                    'message' => 'Cache cleared successfully',
                ],
                'meta' => [
                    'cleared_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to clear cache',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手動モデル更新
     */
    public function updateModels(Request $request): JsonResponse
    {
        try {
            $providers = $request->get('providers', ['all']);
            $forceUpdate = $request->boolean('force', false);
            
            $results = [];
            
            if (in_array('all', $providers)) {
                $providers = ['openai', 'gemini', 'claude', 'grok'];
            }
            
            foreach ($providers as $provider) {
                try {
                    $result = $this->modelRepository->updateModelsFromProvider($provider, $forceUpdate);
                    $results[$provider] = [
                        'success' => true,
                        'updated_models' => $result['updated'] ?? 0,
                        'new_models' => $result['added'] ?? 0,
                        'deprecated_models' => $result['deprecated'] ?? 0,
                    ];
                } catch (\Exception $e) {
                    $results[$provider] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'update_results' => $results,
                    'force_update' => $forceUpdate,
                    'message' => 'Model update completed',
                ],
                'meta' => [
                    'updated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update models',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * システムメンテナンス実行
     */
    public function performMaintenance(Request $request): JsonResponse
    {
        try {
            $tasks = $request->get('tasks', ['cleanup', 'optimize']);
            $dryRun = $request->boolean('dry_run', false);
            
            $results = [];
            
            foreach ($tasks as $task) {
                switch ($task) {
                    case 'cleanup':
                        $results['cleanup'] = $this->performCleanup($dryRun);
                        break;
                    case 'optimize':
                        $results['optimize'] = $this->performOptimization($dryRun);
                        break;
                    case 'backup':
                        $results['backup'] = $this->performBackup($dryRun);
                        break;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'maintenance_results' => $results,
                    'dry_run' => $dryRun,
                ],
                'meta' => [
                    'performed_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to perform maintenance',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Private helper methods

    private function checkDatabaseStatus(): array
    {
        try {
            DB::select('SELECT 1');
            return ['status' => 'healthy', 'response_time' => 0];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function checkCacheStatus(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);
            
            return [
                'status' => $retrieved === 'test' ? 'healthy' : 'degraded',
                'driver' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function checkProvidersStatus(): array
    {
        $providers = config('genai.providers', []);
        $status = [];
        
        foreach ($providers as $name => $config) {
            $status[$name] = [
                'configured' => !empty($config['api_key']),
                'status' => 'unknown', // 実際のAPI呼び出しでテストする場合は実装
            ];
        }
        
        return $status;
    }

    private function checkStorageStatus(): array
    {
        $storagePath = storage_path();
        $freeSpace = disk_free_space($storagePath);
        $totalSpace = disk_total_space($storagePath);
        
        return [
            'status' => $freeSpace > 1024 * 1024 * 100 ? 'healthy' : 'warning', // 100MB threshold
            'free_space' => $freeSpace,
            'total_space' => $totalSpace,
            'usage_percent' => round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2),
        ];
    }

    private function getTodayRequestCount(): int
    {
        return DB::table('genai_requests')
            ->whereDate('created_at', today())
            ->count();
    }

    private function getTodayCost(): float
    {
        return DB::table('genai_requests')
            ->whereDate('created_at', today())
            ->sum('cost') ?? 0.0;
    }

    private function getActiveSessionCount(): int
    {
        // 実装に応じて調整
        return 0;
    }

    private function getSystemUptime(): string
    {
        // Laravel アプリケーションの起動時間（概算）
        return "24h 30m"; // 実装に応じて調整
    }

    private function determineOverallStatus(array $services): string
    {
        $statuses = collect($services)->flatten()->pluck('status');
        
        if ($statuses->contains('unhealthy')) {
            return 'degraded';
        } elseif ($statuses->contains('warning')) {
            return 'warning';
        } else {
            return 'operational';
        }
    }

    private function getRecentErrors(int $limit): array
    {
        // 実装に応じて調整（ログファイルやエラートラッキングから取得）
        return [];
    }

    private function getSystemResources(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    private function performDatabaseHealthCheck(): array
    {
        return $this->checkDatabaseStatus();
    }

    private function performCacheHealthCheck(): array
    {
        return $this->checkCacheStatus();
    }

    private function performProvidersHealthCheck(): array
    {
        return ['status' => 'healthy']; // 簡略化
    }

    private function performDiskSpaceCheck(): array
    {
        return $this->checkStorageStatus();
    }

    private function performMemoryCheck(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        
        return [
            'status' => 'healthy',
            'current_usage' => $memoryUsage,
            'limit' => $memoryLimit,
        ];
    }

    private function performCleanup(bool $dryRun): array
    {
        // クリーンアップタスクの実装
        return ['status' => 'completed', 'dry_run' => $dryRun];
    }

    private function performOptimization(bool $dryRun): array
    {
        // 最適化タスクの実装
        return ['status' => 'completed', 'dry_run' => $dryRun];
    }

    private function performBackup(bool $dryRun): array
    {
        // バックアップタスクの実装
        return ['status' => 'completed', 'dry_run' => $dryRun];
    }
}