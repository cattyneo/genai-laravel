<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use CattyNeo\LaravelGenAI\Services\GenAI\PerformanceMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GenAI パフォーマンス監視 API コントローラー
 */
class PerformanceController extends Controller
{
    public function __construct(
        private PerformanceMonitoringService $performanceService
    ) {
    }

    /**
     * リアルタイムメトリクス取得
     */
    public function getRealTimeMetrics(Request $request): JsonResponse
    {
        try {
            $metrics = $this->performanceService->getRealTimeMetrics();

            return response()->json([
                'success' => true,
                'data' => $metrics,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'realtime_enabled' => config('genai.advanced_services.monitoring.realtime_enabled', true),
                    'collection_interval' => config('genai.advanced_services.monitoring.collection_interval', 60),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get real-time metrics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * パフォーマンストレンド取得
     */
    public function getPerformanceTrends(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', 'last_24_hours');
            $granularity = $request->get('granularity', 'hourly');
            $metrics = $request->get('metrics', ['response_time', 'throughput', 'error_rate']);

            [$startDate, $endDate] = $this->getPeriodDates($period);

            $trends = $this->performanceService->getPerformanceTrends(
                $startDate,
                $endDate,
                $granularity,
                $metrics
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'trends' => $trends,
                    'period' => [
                        'start' => $startDate->toISOString(),
                        'end' => $endDate->toISOString(),
                        'granularity' => $granularity,
                    ],
                    'metrics' => $metrics,
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'data_points' => count($trends),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get performance trends',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * パフォーマンス履歴取得
     */
    public function getPerformanceHistory(Request $request, int $days = null): JsonResponse
    {
        try {
            $days = $days ?? $request->get('days', 7);
            $groupBy = $request->get('group_by', 'provider');
            $includeDetails = $request->boolean('include_details', false);

            $endDate = now();
            $startDate = $endDate->copy()->subDays($days);

            $history = $this->performanceService->getPerformanceHistory(
                $startDate,
                $endDate,
                $groupBy,
                $includeDetails
            );

            $summary = $this->performanceService->getPerformanceSummary($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'history' => $history,
                    'summary' => $summary,
                    'period' => [
                        'days' => $days,
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ],
                    'group_by' => $groupBy,
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'include_details' => $includeDetails,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get performance history',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * パフォーマンスアラート取得
     */
    public function getPerformanceAlerts(Request $request): JsonResponse
    {
        try {
            $status = $request->get('status', 'active'); // active, resolved, all
            $severity = $request->get('severity'); // critical, warning, info
            $limit = $request->get('limit', 50);

            $alerts = $this->performanceService->getPerformanceAlerts($status, $severity, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts,
                    'filters' => [
                        'status' => $status,
                        'severity' => $severity,
                        'limit' => $limit,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'alert_count' => count($alerts),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get performance alerts',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * パフォーマンス比較取得
     */
    public function getPerformanceComparison(Request $request): JsonResponse
    {
        try {
            $providers = $request->get('providers', []);
            $models = $request->get('models', []);
            $period = $request->get('period', 'last_7_days');
            $metrics = $request->get('metrics', ['response_time', 'success_rate', 'throughput']);

            [$startDate, $endDate] = $this->getPeriodDates($period);

            $comparison = $this->performanceService->comparePerformance(
                $providers,
                $models,
                $startDate,
                $endDate,
                $metrics
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'comparison' => $comparison,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ],
                    'filters' => [
                        'providers' => $providers,
                        'models' => $models,
                        'metrics' => $metrics,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get performance comparison',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * システム健康状態取得
     */
    public function getSystemHealth(Request $request): JsonResponse
    {
        try {
            $includeDetails = $request->boolean('include_details', false);

            $health = $this->performanceService->getSystemHealth($includeDetails);

            return response()->json([
                'success' => true,
                'data' => $health,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'include_details' => $includeDetails,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get system health',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * パフォーマンス設定更新
     */
    public function updatePerformanceSettings(Request $request): JsonResponse
    {
        try {
            $settings = $request->validate([
                'collection_interval' => 'sometimes|integer|min:30|max:3600',
                'alert_thresholds' => 'sometimes|array',
                'alert_thresholds.response_time_p95' => 'sometimes|integer|min:1000',
                'alert_thresholds.error_rate' => 'sometimes|numeric|min:0|max:100',
                'alert_thresholds.throughput_drop' => 'sometimes|numeric|min:0|max:100',
                'alert_cooldown' => 'sometimes|integer|min:60|max:3600',
                'realtime_enabled' => 'sometimes|boolean',
            ]);

            $updated = $this->performanceService->updateSettings($settings);

            return response()->json([
                'success' => true,
                'data' => [
                    'updated_settings' => $updated,
                    'message' => 'Performance settings updated successfully',
                ],
                'meta' => [
                    'updated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update performance settings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 期間から開始日・終了日を取得
     */
    private function getPeriodDates(string $period): array
    {
        $now = now();

        return match ($period) {
            'last_hour' => [$now->copy()->subHour(), $now],
            'last_6_hours' => [$now->copy()->subHours(6), $now],
            'last_24_hours' => [$now->copy()->subDay(), $now],
            'last_7_days' => [$now->copy()->subDays(7), $now],
            'last_30_days' => [$now->copy()->subDays(30), $now],
            'current_week' => [$now->copy()->startOfWeek(), $now],
            'current_month' => [$now->copy()->startOfMonth(), $now],
            default => [$now->copy()->subDay(), $now],
        };
    }
}
