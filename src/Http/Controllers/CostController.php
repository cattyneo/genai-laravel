<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Services\GenAI\CostOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GenAI コスト分析 API コントローラー
 */
class CostController extends Controller
{
    public function __construct(
        private CostOptimizationService $costService
    ) {}

    /**
     * 月次コストレポート取得
     */
    public function getMonthlyReport(Request $request, ?string $month = null): JsonResponse
    {
        try {
            $report = $this->costService->generateMonthlyReport($month);

            return response()->json([
                'success' => true,
                'data' => $report,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'cache_enabled' => config('genai.cache.enabled', false),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate monthly report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 週次コストレポート取得
     */
    public function getWeeklyReport(Request $request, ?string $week = null): JsonResponse
    {
        try {
            $targetWeek = $week ? Carbon::parse($week) : now();
            $startDate = $targetWeek->copy()->startOfWeek();
            $endDate = $targetWeek->copy()->endOfWeek();

            $report = [
                'period' => [
                    'type' => 'weekly',
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'week' => $targetWeek->format('Y-W'),
                ],
                'summary' => $this->costService->generateCostSummary($startDate, $endDate),
                'breakdown' => $this->costService->getProviderCostBreakdown($startDate, $endDate),
                'daily_trends' => $this->costService->getDailyCostBreakdown($startDate, $endDate),
                'optimization_opportunities' => $this->costService->identifyOptimizationOpportunities($startDate, $endDate),
            ];

            return response()->json([
                'success' => true,
                'data' => $report,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate weekly report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * コスト概要取得
     */
    public function getCostSummary(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', 'current_month');

            [$startDate, $endDate] = $this->getPeriodDates($period);

            $summary = [
                'period' => [
                    'type' => $period,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'cost_summary' => $this->costService->generateCostSummary($startDate, $endDate),
                'top_models' => $this->costService->getTopModels($startDate, $endDate, 5),
                'provider_distribution' => $this->costService->getProviderCostBreakdown($startDate, $endDate),
                'recent_trends' => $this->costService->getRecentTrends($period),
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get cost summary',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * コスト最適化機会取得
     */
    public function getOptimizationOpportunities(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', 'last_30_days');
            $threshold = $request->get('savings_threshold', 10.0);

            [$startDate, $endDate] = $this->getPeriodDates($period);

            $opportunities = $this->costService->identifyOptimizationOpportunities($startDate, $endDate);

            // しきい値でフィルタリング
            $filteredOpportunities = array_filter($opportunities, function ($opportunity) use ($threshold) {
                return ($opportunity['potential_savings_percent'] ?? 0) >= $threshold;
            });

            // 節約額でソート
            usort($filteredOpportunities, function ($a, $b) {
                return ($b['potential_savings_amount'] ?? 0) <=> ($a['potential_savings_amount'] ?? 0);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'opportunities' => $filteredOpportunities,
                    'total_potential_savings' => array_sum(array_column($filteredOpportunities, 'potential_savings_amount')),
                    'savings_threshold' => $threshold,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'opportunity_count' => count($filteredOpportunities),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get optimization opportunities',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * コスト予算状況取得
     */
    public function getBudgetStatus(Request $request): JsonResponse
    {
        try {
            $budgetLimits = config('genai.advanced_services.cost_optimization.budget_limits', []);
            $currentCosts = $this->costService->getCurrentPeriodCosts();

            $status = [];
            foreach ($budgetLimits as $period => $limit) {
                if ($limit) {
                    $currentCost = $currentCosts[$period] ?? 0;
                    $usagePercent = $limit > 0 ? ($currentCost / $limit) * 100 : 0;

                    $status[$period] = [
                        'limit' => $limit,
                        'current' => $currentCost,
                        'remaining' => max(0, $limit - $currentCost),
                        'usage_percent' => round($usagePercent, 2),
                        'status' => $this->getBudgetStatusLevel($usagePercent),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'budget_status' => $status,
                    'currency' => config('genai.pricing.currency', 'USD'),
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get budget status',
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
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(7), $now],
            'last_30_days' => [$now->copy()->subDays(30), $now],
            'current_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /**
     * 予算使用状況レベル取得
     */
    private function getBudgetStatusLevel(float $usagePercent): string
    {
        if ($usagePercent >= 100) {
            return 'exceeded';
        } elseif ($usagePercent >= 90) {
            return 'critical';
        } elseif ($usagePercent >= 75) {
            return 'warning';
        } else {
            return 'normal';
        }
    }
}
