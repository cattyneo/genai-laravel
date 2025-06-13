<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * コスト最適化サービス
 *
 * 月次・週次レポート生成、コスト分析、最適化提案
 */
class CostOptimizationService
{
    private const CACHE_PREFIX = 'genai_cost_';

    public function __construct(
        private ModelReplacementService $replacementService,
        private NotificationService $notificationService
    ) {}

    /**
     * 月次コスト最適化レポート生成
     */
    public function generateMonthlyReport(?string $month = null): array
    {
        $targetMonth = $month ? Carbon::parse($month) : now();
        $startDate = $targetMonth->copy()->startOfMonth();
        $endDate = $targetMonth->copy()->endOfMonth();

        $report = [
            'period' => [
                'type' => 'monthly',
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'month' => $targetMonth->format('Y-m'),
            ],
            'summary' => $this->generateCostSummary($startDate, $endDate),
            'breakdown' => $this->getProviderCostBreakdown($startDate, $endDate),
            'provider_breakdown' => $this->getProviderCostBreakdown($startDate, $endDate),
            'model_analysis' => $this->getModelCostAnalysis($startDate, $endDate),
            'usage_patterns' => $this->analyzeUsagePatterns($startDate, $endDate),
            'trends' => $this->getDailyCostBreakdown($startDate, $endDate),
            'optimization_opportunities' => $this->identifyOptimizationOpportunities($startDate, $endDate),
            'cost_predictions' => $this->generateCostPredictions($startDate, $endDate),
            'recommendations' => $this->generateOptimizationRecommendations($startDate, $endDate),
        ];

        // アラートチェック
        $this->checkCostAlerts($report);

        return $report;
    }

    /**
     * 週次コスト最適化レポート生成
     */
    public function generateWeeklyReport(?string $week = null): array
    {
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
            'summary' => $this->generateCostSummary($startDate, $endDate),
            'daily_breakdown' => $this->getDailyCostBreakdown($startDate, $endDate),
            'peak_usage_analysis' => $this->analyzePeakUsage($startDate, $endDate),
            'efficiency_metrics' => $this->calculateEfficiencyMetrics($startDate, $endDate),
            'quick_wins' => $this->identifyQuickWins($startDate, $endDate),
        ];

        return $report;
    }

    /**
     * コストサマリー生成
     */
    private function generateCostSummary(Carbon $startDate, Carbon $endDate): array
    {
        $currentPeriodCosts = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                SUM(cost) as total_cost,
                COUNT(*) as total_requests,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                AVG(cost) as avg_cost_per_request,
                SUM(cost) as total_input_cost,
                SUM(cost) as total_output_cost
            ')
            ->first();

        // 前期比較用データ
        $periodLength = $endDate->diffInDays($startDate) + 1;
        $previousPeriodStart = $startDate->copy()->subDays($periodLength);
        $previousPeriodEnd = $startDate->copy()->subDay();

        $previousPeriodCosts = DB::table('genai_requests')
            ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
            ->selectRaw('
                SUM(cost) as total_cost,
                COUNT(*) as total_requests
            ')
            ->first();

        $costChange = $previousPeriodCosts->total_cost > 0 ?
            (($currentPeriodCosts->total_cost - $previousPeriodCosts->total_cost) / $previousPeriodCosts->total_cost) * 100 : 0;

        $requestChange = $previousPeriodCosts->total_requests > 0 ?
            (($currentPeriodCosts->total_requests - $previousPeriodCosts->total_requests) / $previousPeriodCosts->total_requests) * 100 : 0;

        // トッププロバイダー取得
        $topProviders = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('provider, SUM(cost) as total_cost')
            ->groupBy('provider')
            ->orderByDesc('total_cost')
            ->limit(3)
            ->get()
            ->pluck('provider')
            ->toArray();

        return [
            'total_cost' => (float) $currentPeriodCosts->total_cost,
            'total_input_cost' => (float) $currentPeriodCosts->total_input_cost,
            'total_output_cost' => (float) $currentPeriodCosts->total_output_cost,
            'total_requests' => (int) $currentPeriodCosts->total_requests,
            'total_input_tokens' => (int) $currentPeriodCosts->total_input_tokens,
            'total_output_tokens' => (int) $currentPeriodCosts->total_output_tokens,
            'avg_cost_per_request' => (float) $currentPeriodCosts->avg_cost_per_request,
            'cost_per_token' => $currentPeriodCosts->total_input_tokens + $currentPeriodCosts->total_output_tokens > 0 ?
                $currentPeriodCosts->total_cost / ($currentPeriodCosts->total_input_tokens + $currentPeriodCosts->total_output_tokens) : 0,
            'top_providers' => $topProviders,
            'period_comparison' => [
                'cost_change_percent' => round($costChange, 2),
                'request_change_percent' => round($requestChange, 2),
                'previous_period_cost' => (float) $previousPeriodCosts->total_cost,
                'previous_period_requests' => (int) $previousPeriodCosts->total_requests,
            ],
        ];
    }

    /**
     * プロバイダー別コスト内訳
     */
    private function getProviderCostBreakdown(Carbon $startDate, Carbon $endDate): array
    {
        $breakdown = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                provider,
                SUM(cost) as total_cost,
                SUM(cost) as input_cost,
                SUM(cost) as output_cost,
                COUNT(*) as request_count,
                AVG(cost) as avg_cost_per_request,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens
            ')
            ->groupBy('provider')
            ->orderByDesc('total_cost')
            ->get();

        $totalCost = $breakdown->sum('total_cost');

        return $breakdown->map(function ($provider) use ($totalCost) {
            return [
                'provider' => $provider->provider,
                'total_cost' => (float) $provider->total_cost,
                'input_cost' => (float) $provider->input_cost,
                'output_cost' => (float) $provider->output_cost,
                'request_count' => (int) $provider->request_count,
                'avg_cost_per_request' => (float) $provider->avg_cost_per_request,
                'total_tokens' => (int) $provider->total_input_tokens + (int) $provider->total_output_tokens,
                'cost_percentage' => $totalCost > 0 ? round(($provider->total_cost / $totalCost) * 100, 2) : 0,
                'cost_per_token' => ($provider->total_input_tokens + $provider->total_output_tokens) > 0 ?
                    $provider->total_cost / ($provider->total_input_tokens + $provider->total_output_tokens) : 0,
            ];
        })->toArray();
    }

    /**
     * モデル別コスト分析
     */
    private function getModelCostAnalysis(Carbon $startDate, Carbon $endDate): array
    {
        $analysis = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                provider,
                model,
                SUM(cost) as total_cost,
                COUNT(*) as request_count,
                AVG(cost) as avg_cost_per_request,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                AVG(duration_ms) as avg_response_time,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating ELSE NULL END) as avg_rating
            ')
            ->groupBy(['provider', 'model'])
            ->orderByDesc('total_cost')
            ->get();

        return $analysis->map(function ($model) {
            $totalTokens = (int) $model->total_input_tokens + (int) $model->total_output_tokens;

            return [
                'provider' => $model->provider,
                'model' => $model->model,
                'total_cost' => (float) $model->total_cost,
                'request_count' => (int) $model->request_count,
                'avg_cost_per_request' => (float) $model->avg_cost_per_request,
                'total_tokens' => $totalTokens,
                'cost_per_token' => $totalTokens > 0 ? $model->total_cost / $totalTokens : 0,
                'avg_response_time' => (float) $model->avg_response_time,
                'avg_rating' => $model->avg_rating ? (float) $model->avg_rating : null,
                'cost_efficiency_score' => $this->calculateCostEfficiencyScore($model),
            ];
        })->toArray();
    }

    /**
     * 使用パターン分析
     */
    private function analyzeUsagePatterns(Carbon $startDate, Carbon $endDate): array
    {
        // 時間別使用パターン
        $hourlyUsage = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                strftime("%H", created_at) as hour,
                COUNT(*) as request_count,
                SUM(cost) as total_cost
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        // 日別使用パターン
        $dailyUsage = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as request_count,
                SUM(cost) as total_cost
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // 使用ケース別パターン
        $useCaseUsage = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                use_case,
                COUNT(*) as request_count,
                SUM(cost) as total_cost,
                AVG(cost) as avg_cost
            ')
            ->groupBy('use_case')
            ->orderByDesc('total_cost')
            ->get();

        return [
            'hourly_usage' => $hourlyUsage->toArray(),
            'daily_usage' => $dailyUsage->toArray(),
            'use_case_usage' => $useCaseUsage->toArray(),
            'peak_hours' => $this->identifyPeakHours($hourlyUsage),
            'usage_concentration' => $this->calculateUsageConcentration($hourlyUsage),
        ];
    }

    /**
     * 高コストモデル分析の共通クエリ（キャッシュ対応）
     */
    private function getHighCostModelsQuery(
        Carbon $startDate,
        Carbon $endDate,
        float $minCost = 500,
        int $limit = null
    ): \Illuminate\Support\Collection {
        $cacheKey = "high_cost_models_" . $startDate->format('Y-m-d') . "_" . $endDate->format('Y-m-d') . "_" . $minCost;

        return Cache::remember($cacheKey, 300, function () use ($startDate, $endDate, $minCost, $limit) {
            $query = DB::table('genai_requests')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    provider,
                    model,
                    SUM(cost) as total_cost,
                    COUNT(*) as request_count,
                    AVG(cost) as avg_cost,
                    AVG(total_tokens) as avg_tokens
                ')
                ->groupBy(['provider', 'model'])
                ->having('total_cost', '>', $minCost)
                ->orderByDesc('total_cost');

            if ($limit) {
                $query->limit($limit);
            }

            return $query->get();
        });
    }

    /**
     * 最適化機会の特定
     */
    public function identifyOptimizationOpportunities(Carbon $startDate, Carbon $endDate): array
    {
        $opportunities = [];

        // 高コストモデルの代替提案（キャッシュ対応クエリ使用）
        $highCostModels = $this->getHighCostModelsQuery($startDate, $endDate, 1000, 10);

        foreach ($highCostModels as $model) {
            $replacements = $this->replacementService->findReplacements(
                $model->model,
                $model->provider,
                ['cost_optimization' => true]
            );

            if (!empty($replacements)) {
                $opportunities[] = [
                    'type' => 'model_replacement',
                    'current_model' => $model->model,
                    'current_provider' => $model->provider,
                    'current_cost' => (float) $model->total_cost,
                    'request_count' => (int) $model->request_count,
                    'avg_cost' => (float) $model->avg_cost,
                    'suggested_replacements' => array_slice($replacements, 0, 3),
                    'potential_savings' => $this->calculateModelSavings($model, $replacements[0] ?? []),
                ];
            }
        }

        // キャッシュ効率とバッチ処理機会を並列処理
        $cacheOpportunities = $this->identifyCacheOpportunities($startDate, $endDate);
        $batchOpportunities = $this->identifyBatchOpportunities($startDate, $endDate);

        return array_merge($opportunities, $cacheOpportunities, $batchOpportunities);
    }

    /**
     * 高度な推奨事項生成（最適化版）
     */
    public function generateOptimizationRecommendations(Carbon $startDate, Carbon $endDate): array
    {
        $recommendations = [];

        // 高コストモデル分析（共通クエリ使用）
        $highCostModels = $this->getHighCostModelsQuery($startDate, $endDate, 500, 5);

        foreach ($highCostModels as $model) {
            if ($model->total_cost > 500) {
                $recommendations[] = [
                    'priority' => $model->total_cost > 1000 ? 'high' : 'medium',
                    'type' => 'cost_reduction',
                    'subject' => "{$model->provider}/{$model->model}",
                    'current_cost' => (float) $model->total_cost,
                    'avg_cost_per_request' => (float) $model->avg_cost,
                    'recommendation' => 'Consider switching to a more cost-effective model',
                    'estimated_savings' => $model->total_cost * 0.3,
                    'implementation_effort' => 'medium',
                ];
            }
        }

        // キャッシュ活用推奨（最適化版）
        $cacheMissRate = $this->getCachedCacheMissRate($startDate, $endDate);
        if ($cacheMissRate > 0.8) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'cache_optimization',
                'subject' => 'Request Caching',
                'current_cache_miss_rate' => $cacheMissRate,
                'recommendation' => 'Improve caching strategy to reduce redundant API calls',
                'estimated_savings' => $this->estimateCacheSavings($startDate, $endDate),
                'implementation_effort' => 'low',
            ];
        }

        return $recommendations;
    }

    /**
     * コスト予測生成
     */
    private function generateCostPredictions(Carbon $startDate, Carbon $endDate): array
    {
        $periodDays = $endDate->diffInDays($startDate) + 1;
        $dailyAvgCost = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('AVG(daily_cost) as avg_daily_cost')
            ->from(DB::raw('(
                SELECT DATE(created_at) as date, SUM(cost) as daily_cost
                FROM genai_requests
                WHERE created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
            ) as daily_costs'))
            ->setBindings([$startDate, $endDate])
            ->value('avg_daily_cost');

        $trendData = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(cost) as daily_cost
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $trend = $this->calculateCostTrend($trendData);

        return [
            'next_week_prediction' => $dailyAvgCost * 7 * (1 + $trend['growth_rate']),
            'next_month_prediction' => $dailyAvgCost * 30 * (1 + $trend['growth_rate']),
            'trend_analysis' => $trend,
            'confidence_level' => $this->calculatePredictionConfidence($trendData),
            'factors' => [
                'historical_growth' => $trend['growth_rate'],
                'seasonal_adjustment' => 0, // 簡略化
                'usage_pattern_stability' => $this->calculateUsageStability($trendData),
            ],
        ];
    }

    /**
     * コストアラートチェック
     */
    private function checkCostAlerts(array $report): void
    {
        $thresholds = config('genai.notifications.cost_thresholds', []);
        $totalCost = $report['summary']['total_cost'];

        $warningThreshold = $thresholds['warning'] ?? 10000;
        $criticalThreshold = $thresholds['critical'] ?? 50000;

        if ($totalCost > $criticalThreshold) {
            $this->notificationService->sendCostAlert([
                'current_spend' => $totalCost,
                'budget_threshold' => $criticalThreshold,
                'budget_exceed_percent' => ($totalCost / $criticalThreshold) * 100,
                'threshold_exceeded' => true,
                'period' => $report['period'],
                'top_cost_models' => array_slice($report['model_analysis'], 0, 3),
            ]);
        } elseif ($totalCost > $warningThreshold) {
            $this->notificationService->sendCostAlert([
                'current_spend' => $totalCost,
                'budget_threshold' => $warningThreshold,
                'budget_exceed_percent' => ($totalCost / $warningThreshold) * 100,
                'threshold_exceeded' => false,
                'period' => $report['period'],
            ]);
        }
    }

    // ヘルパーメソッド（簡略化実装）
    private function calculateCostEfficiencyScore($model): float
    {
        // 簡略化された効率スコア計算
        $totalTokens = (int) $model->total_input_tokens + (int) $model->total_output_tokens;
        $costPerToken = $totalTokens > 0 ? $model->total_cost / $totalTokens : 0;
        $responseTime = $model->avg_response_time ?? 1000;
        $rating = $model->avg_rating ?? 3.0;

        return ($rating / 5.0) * (1000 / max($responseTime, 100)) * (1 / max($costPerToken, 0.0001));
    }

    private function identifyPeakHours($hourlyUsage): array
    {
        return $hourlyUsage->sortByDesc('request_count')->take(3)->values()->toArray();
    }

    private function calculateUsageConcentration($hourlyUsage): float
    {
        $totalRequests = $hourlyUsage->sum('request_count');
        $topHours = $hourlyUsage->sortByDesc('request_count')->take(4);
        $topHoursRequests = $topHours->sum('request_count');

        return $totalRequests > 0 ? ($topHoursRequests / $totalRequests) : 0;
    }

    private function identifyCacheOpportunities(Carbon $startDate, Carbon $endDate): array
    {
        // 簡略化実装
        return [
            'potential_savings' => 0.0,
            'opportunities' => [],
        ];
    }

    private function identifyBatchOpportunities(Carbon $startDate, Carbon $endDate): array
    {
        // 簡略化実装
        return [
            'potential_savings' => 0.0,
            'opportunities' => [],
        ];
    }

    private function calculateCostTrend($trendData): array
    {
        // 配列またはCollectionかどうかチェック
        if (is_array($trendData)) {
            $dataCount = count($trendData);
            $costs = array_column($trendData, 'daily_cost');
        } else {
            $dataCount = $trendData->count();
            $costs = $trendData->pluck('daily_cost')->toArray();
        }

        if ($dataCount < 2) {
            return [
                'growth_rate' => 0,
                'direction' => 'stable',
                'confidence' => 0,
                'percentage' => 0,
            ];
        }

        $n = count($costs);
        $avgCost = array_sum($costs) / $n;

        $firstHalf = array_slice($costs, 0, intval($n / 2));
        $secondHalf = array_slice($costs, intval($n / 2));

        $firstAvg = count($firstHalf) > 0 ? array_sum($firstHalf) / count($firstHalf) : 0;
        $secondAvg = count($secondHalf) > 0 ? array_sum($secondHalf) / count($secondHalf) : 0;

        $growthRate = $firstAvg > 0 ? ($secondAvg - $firstAvg) / $firstAvg : 0;
        $percentage = $growthRate * 100;

        return [
            'growth_rate' => $growthRate,
            'percentage' => $percentage,
            'direction' => $growthRate > 0.1 ? 'increasing' : ($growthRate < -0.1 ? 'decreasing' : 'stable'),
            'confidence' => min(abs($growthRate) * 10, 1.0),
        ];
    }

    private function calculatePredictionConfidence($trendData): float
    {
        // データ点数と変動性から信頼度を計算
        return min($trendData->count() / 30, 1.0); // 30日分で最大信頼度
    }

    private function calculateUsageStability($trendData): float
    {
        if ($trendData->count() < 2) return 0;

        $costs = $trendData->pluck('daily_cost')->toArray();
        $mean = array_sum($costs) / count($costs);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $costs)) / count($costs);
        $stdDev = sqrt($variance);

        return $mean > 0 ? max(0, 1 - ($stdDev / $mean)) : 0;
    }

    private function getDailyCostBreakdown(Carbon $startDate, Carbon $endDate): array
    {
        return DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(cost) as total_cost,
                COUNT(*) as request_count
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function analyzePeakUsage(Carbon $startDate, Carbon $endDate): array
    {
        return [
            'peak_day' => DB::table('genai_requests')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('DATE(created_at) as date, SUM(cost) as daily_cost')
                ->groupBy('date')
                ->orderByDesc('daily_cost')
                ->first(),
            'peak_hour' => DB::table('genai_requests')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('strftime("%H", created_at) as hour, SUM(cost) as hourly_cost')
                ->groupBy('hour')
                ->orderByDesc('hourly_cost')
                ->first(),
        ];
    }

    private function calculateEfficiencyMetrics(Carbon $startDate, Carbon $endDate): array
    {
        return [
            'cost_per_token' => DB::table('genai_requests')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('AVG(cost / (input_tokens + output_tokens)) as avg_cost_per_token')
                ->value('avg_cost_per_token'),
            'cache_hit_rate' => DB::table('genai_requests')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('AVG(CASE WHEN is_cached = 1 THEN 1 ELSE 0 END) as cache_hit_rate')
                ->value('cache_hit_rate'),
        ];
    }

    private function identifyQuickWins(Carbon $startDate, Carbon $endDate): array
    {
        return [
            [
                'type' => 'cache_optimization',
                'description' => 'Enable caching for repeated queries',
                'potential_savings' => 'Up to 30% cost reduction',
                'effort' => 'Low',
            ],
        ];
    }

    private function calculateCacheMissRate(Carbon $startDate, Carbon $endDate): float
    {
        $missRate = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('AVG(CASE WHEN is_cached = 0 THEN 1 ELSE 0 END) as miss_rate')
            ->value('miss_rate');

        return (float) $missRate;
    }

    private function estimateCacheSavings(Carbon $startDate, Carbon $endDate): float
    {
        $totalCost = DB::table('genai_requests')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('cost');

        return $totalCost * 0.25; // 25%削減想定
    }

    /**
     * コストトレンド分析
     */
    public function analyzeCostTrends(string $period = '7d'): array
    {
        // 最適化機能が無効な場合
        if (!config('genai.cost_optimization.enabled', true)) {
            return [
                'status' => 'disabled',
                'message' => 'Cost optimization is disabled',
            ];
        }

        $days = (int) str_replace('d', '', $period);
        $startDate = now()->subDays($days);
        $endDate = now();

        $dailyCosts = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(cost) as daily_cost, COUNT(*) as daily_requests')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalCost = $dailyCosts->sum('daily_cost');
        $avgDailyCost = $dailyCosts->avg('daily_cost');

        // トレンド計算
        $trend = $this->calculateCostTrend($dailyCosts->toArray());

        return [
            'period' => $period,
            'total_cost' => $totalCost,
            'daily_average' => $avgDailyCost,
            'trend_direction' => $trend['direction'],
            'trend_percentage' => $trend['percentage'],
            'cost_by_provider' => $this->getProviderCostBreakdown($startDate, $endDate),
            'daily_breakdown' => $dailyCosts->toArray(),
        ];
    }

    /**
     * 高コストモデル特定
     */
    public function identifyExpensiveModels(): array
    {
        $expensiveModels = GenAIRequest::selectRaw('
                provider,
                model,
                SUM(cost) as total_cost,
                COUNT(*) as request_count,
                AVG(cost) as avg_cost_per_request,
                SUM(cost) / SUM(total_tokens) as cost_per_token
            ')
            ->whereNotNull('cost')
            ->groupBy(['provider', 'model'])
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        return $expensiveModels->map(function ($model) {
            return [
                'provider' => $model->provider,
                'model' => $model->model,
                'total_cost' => (float) $model->total_cost,
                'request_count' => (int) $model->request_count,
                'avg_cost_per_request' => (float) $model->avg_cost_per_request,
                'cost_per_token' => (float) $model->cost_per_token,
                'optimization_potential' => $this->calculateOptimizationPotential($model),
            ];
        })->toArray();
    }

    /**
     * 最適化提案生成
     */
    public function generateOptimizationSuggestions(): array
    {
        $suggestions = [];

        // 高コストモデルの代替提案
        $expensiveModels = $this->identifyExpensiveModels();
        foreach (array_slice($expensiveModels, 0, 3) as $model) {
            $alternatives = $this->replacementService->findReplacements(
                $model['model'],
                $model['provider'],
                ['use_case' => 'cost_optimization']
            );

            if (!empty($alternatives)) {
                $suggestions[] = [
                    'type' => 'model_replacement',
                    'description' => "Replace {$model['provider']}/{$model['model']} with a more cost-effective alternative",
                    'current_model' => $model,
                    'suggested_alternatives' => array_slice($alternatives, 0, 2),
                    'potential_savings' => $this->calculatePotentialSavings($model, $alternatives[0] ?? []),
                    'implementation_effort' => 'low',
                ];
            }
        }

        // キャッシュ最適化
        $cacheOpportunities = $this->identifyCacheOpportunities(now()->subDays(7), now());
        if ($cacheOpportunities['potential_savings'] > 0) {
            $suggestions[] = [
                'type' => 'cache_optimization',
                'description' => 'Enable caching for frequently repeated requests',
                'potential_savings' => $cacheOpportunities['potential_savings'],
                'implementation_effort' => 'medium',
                'details' => $cacheOpportunities,
            ];
        }

        return $suggestions;
    }

    /**
     * 潜在的節約計算
     */
    public function calculatePotentialSavings(
        array $currentModel,
        array $alternativeModel,
        int $monthlyTokens = null
    ): array {
        if ($monthlyTokens === null) {
            // 過去30日の実際のトークン使用量を計算
            $monthlyTokens = GenAIRequest::where('provider', $currentModel['provider'])
                ->where('model', $currentModel['model'])
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('total_tokens');
        }

        $currentCostPerToken = $currentModel['avg_cost_per_token'] ?? 0.00003;
        $alternativeCostPerToken = $alternativeModel['avg_cost_per_token'] ?? 0.00001;

        $currentCost = $currentCostPerToken * $monthlyTokens;
        $alternativeCost = $alternativeCostPerToken * $monthlyTokens;
        $potentialSavings = $currentCost - $alternativeCost;
        $savingsPercentage = $currentCost > 0 ? ($potentialSavings / $currentCost) * 100 : 0;

        return [
            'current_cost' => $currentCost,
            'alternative_cost' => $alternativeCost,
            'potential_savings' => max(0, $potentialSavings),
            'savings_percentage' => round($savingsPercentage, 2),
            'monthly_tokens' => $monthlyTokens,
        ];
    }

    /**
     * 予算ステータス確認
     */
    public function checkBudgetStatus(string $period): array
    {
        $budgetLimits = $this->getBudgetLimits();
        $limit = $budgetLimits[$period] ?? 0;

        $startDate = match ($period) {
            'daily' => now()->startOfDay(),
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        $currentUsage = GenAIRequest::where('created_at', '>=', $startDate)
            ->sum('cost');

        $usagePercentage = $limit > 0 ? ($currentUsage / $limit) * 100 : 0;
        $remaining = max(0, $limit - $currentUsage);

        $status = match (true) {
            $usagePercentage >= 100 => 'exceeded',
            $usagePercentage >= 80 => 'warning',
            $usagePercentage >= 60 => 'caution',
            default => 'normal',
        };

        return [
            'period' => $period,
            'limit' => $limit,
            'current_usage' => $currentUsage,
            'remaining' => $remaining,
            'usage_percentage' => round($usagePercentage, 2),
            'status' => $status,
        ];
    }

    /**
     * 予算アラート生成
     */
    public function generateBudgetAlerts(): array
    {
        $alerts = [];
        $periods = ['daily', 'weekly', 'monthly'];

        foreach ($periods as $period) {
            $status = $this->checkBudgetStatus($period);

            if (in_array($status['status'], ['warning', 'exceeded'])) {
                $alerts[] = [
                    'type' => 'budget_alert',
                    'period' => $period,
                    'severity' => $status['status'] === 'exceeded' ? 'critical' : 'warning',
                    'current_usage' => $status['current_usage'],
                    'limit' => $status['limit'],
                    'usage_percentage' => $status['usage_percentage'],
                    'message' => $this->generateBudgetAlertMessage($status),
                ];
            }
        }

        return $alerts;
    }

    /**
     * コストレポート生成
     */
    public function generateCostReport(string $period): array
    {
        return match ($period) {
            'monthly' => $this->generateMonthlyReport(),
            'weekly' => $this->generateWeeklyReport(),
            default => $this->generateMonthlyReport(),
        };
    }

    /**
     * 代替モデル推奨
     */
    public function recommendAlternativeModels(array $currentUsage): array
    {
        $recommendations = [];

        $alternatives = $this->replacementService->findReplacements(
            $currentUsage['model'],
            $currentUsage['provider'],
            ['use_case' => 'cost_optimization']
        );

        foreach ($alternatives as $alternative) {
            $costSavings = $this->calculatePotentialSavings(
                $currentUsage,
                $alternative,
                $currentUsage['monthly_requests'] * $currentUsage['avg_tokens_per_request']
            );

            $recommendations[] = [
                'alternative_provider' => $alternative['provider'],
                'alternative_model' => $alternative['model'],
                'cost_savings' => $costSavings,
                'quality_score' => $alternative['performance_score'] ?? 0.8,
                'recommendation_reason' => $this->generateRecommendationReason($alternative, $costSavings),
            ];
        }

        return $recommendations;
    }

    /**
     * リクエスト頻度最適化
     */
    public function optimizeRequestFrequency(): array
    {
        $duplicateRequests = $this->identifyDuplicateRequests();
        $cacheOpportunities = $this->identifyCacheOpportunities(now()->subDays(7), now());
        $batchOpportunities = $this->identifyBatchOpportunities(now()->subDays(7), now());

        $totalPotentialSavings =
            $duplicateRequests['potential_savings'] +
            $cacheOpportunities['potential_savings'] +
            $batchOpportunities['potential_savings'];

        return [
            'duplicate_requests' => $duplicateRequests,
            'cache_opportunities' => $cacheOpportunities,
            'batch_opportunities' => $batchOpportunities,
            'potential_savings' => $totalPotentialSavings,
        ];
    }

    /**
     * 機能別コスト追跡
     */
    public function trackCostByFeature(): array
    {
        // 簡略化実装：プロバイダー別にコスト集計
        $costByProvider = GenAIRequest::selectRaw('
                provider as feature,
                SUM(cost) as total_cost
            ')
            ->groupBy('provider')
            ->get();

        $result = [];
        foreach ($costByProvider as $item) {
            $feature = $item->feature ?: 'unknown';
            $result[$feature] = (float) $item->total_cost;
        }

        return $result;
    }

    /**
     * 予算制限設定
     */
    public function setBudgetLimits(array $limits): array
    {
        // バリデーション
        foreach ($limits as $period => $limit) {
            if (!is_numeric($limit) || $limit < 0) {
                throw new \InvalidArgumentException("Invalid budget limit for {$period}: {$limit}");
            }
        }

        // キャッシュに保存
        Cache::put('genai_budget_limits', $limits, now()->addDays(30));

        return ['success' => true, 'limits' => $limits];
    }

    /**
     * 予算制限取得
     */
    public function getBudgetLimits(): array
    {
        return Cache::get('genai_budget_limits', [
            'daily' => 100.0,
            'weekly' => 500.0,
            'monthly' => 2000.0,
        ]);
    }

    /**
     * コスト予測
     */
    public function forecastCosts(string $period): array
    {
        $days = (int) str_replace('d', '', $period);
        $historicalData = GenAIRequest::where('created_at', '>=', now()->subDays($days * 2))
            ->selectRaw('DATE(created_at) as date, SUM(cost) as daily_cost')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $trend = $this->calculateCostTrend($historicalData->toArray());
        $averageDailyCost = $historicalData->avg('daily_cost');

        // 簡単な線形予測
        $trendFactor = 1 + ($trend['percentage'] / 100);
        $predictedCost = $averageDailyCost * $days * $trendFactor;

        return [
            'predicted_cost' => $predictedCost,
            'confidence_interval' => [
                'low' => $predictedCost * 0.8,
                'high' => $predictedCost * 1.2,
            ],
            'trend_factors' => [
                'direction' => $trend['direction'],
                'percentage' => $trend['percentage'],
            ],
            'risk_assessment' => $this->assessCostRisk($predictedCost),
        ];
    }

    /**
     * 最適化ポテンシャル計算
     */
    private function calculateOptimizationPotential($model): float
    {
        $costPerToken = $model->cost_per_token ?? 0.00003;
        $requestCount = $model->request_count ?? 0;

        // 高コスト、高使用量ほど最適化ポテンシャルが高い
        $costScore = min($costPerToken * 100000, 1.0); // コストスコア正規化
        $usageScore = min($requestCount / 1000, 1.0);   // 使用量スコア正規化

        return ($costScore + $usageScore) / 2;
    }

    /**
     * 予算アラートメッセージ生成
     */
    private function generateBudgetAlertMessage(array $status): string
    {
        $period = $status['period'];
        $percentage = $status['usage_percentage'];

        if ($status['status'] === 'exceeded') {
            return "Budget limit exceeded for {$period} period. Current usage: {$percentage}% of budget.";
        } else {
            return "Budget warning for {$period} period. Current usage: {$percentage}% of budget.";
        }
    }

    /**
     * 推奨理由生成
     */
    private function generateRecommendationReason(array $alternative, array $costSavings): string
    {
        $savingsPercent = $costSavings['savings_percentage'] ?? 0;
        $provider = $alternative['provider'] ?? 'unknown';
        $model = $alternative['model'] ?? 'unknown';

        return "Switch to {$provider}/{$model} for {$savingsPercent}% cost reduction while maintaining quality.";
    }

    /**
     * 重複リクエスト特定
     */
    private function identifyDuplicateRequests(): array
    {
        // prompt（最初の100文字）でグループ化して重複を特定
        $duplicates = GenAIRequest::selectRaw('
                SUBSTR(prompt, 1, 100) as prompt_snippet,
                COUNT(*) as duplicate_count,
                SUM(cost) as total_cost,
                AVG(cost) as avg_cost
            ')
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy(DB::raw('SUBSTR(prompt, 1, 100)'))
            ->having('duplicate_count', '>', 1)
            ->orderByDesc('total_cost')
            ->get();

        $totalSavings = $duplicates->sum(function ($item) {
            return $item->total_cost * 0.8; // 80%の削減想定
        });

        return [
            'duplicates_found' => $duplicates->count(),
            'potential_savings' => $totalSavings,
            'details' => $duplicates->toArray(),
        ];
    }

    /**
     * コストリスク評価
     */
    private function assessCostRisk(float $predictedCost): string
    {
        $budgetLimits = $this->getBudgetLimits();
        $monthlyLimit = $budgetLimits['monthly'] ?? 2000;

        $riskRatio = $predictedCost / $monthlyLimit;

        return match (true) {
            $riskRatio > 1.2 => 'high',
            $riskRatio > 0.8 => 'medium',
            default => 'low',
        };
    }

    /**
     * キャッシュミス率計算（キャッシュ対応）
     */
    private function getCachedCacheMissRate(Carbon $startDate, Carbon $endDate): float
    {
        $cacheKey = "cache_miss_rate_" . $startDate->format('Y-m-d') . "_" . $endDate->format('Y-m-d');

        return Cache::remember($cacheKey, 300, function () use ($startDate, $endDate) {
            return $this->calculateCacheMissRate($startDate, $endDate);
        });
    }

    /**
     * モデル削減効果計算（最適化版）
     */
    private function calculateModelSavings(object $currentModel, array $alternativeModel): array
    {
        if (empty($alternativeModel) || !isset($alternativeModel['pricing'])) {
            return ['estimated_savings' => 0, 'savings_percentage' => 0];
        }

        $currentCostPerToken = $currentModel->avg_cost / max($currentModel->avg_tokens, 1);
        $alternativeCostPerToken = $alternativeModel['pricing']['input'] ?? $currentCostPerToken;

        $savingsPerToken = max(0, $currentCostPerToken - $alternativeCostPerToken);
        $totalSavings = $savingsPerToken * $currentModel->avg_tokens * $currentModel->request_count;
        $savingsPercentage = $currentCostPerToken > 0 ? ($savingsPerToken / $currentCostPerToken) * 100 : 0;

        return [
            'estimated_savings' => $totalSavings,
            'savings_percentage' => $savingsPercentage,
            'current_cost_per_token' => $currentCostPerToken,
            'alternative_cost_per_token' => $alternativeCostPerToken
        ];
    }
}
