<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Commands;

use Illuminate\Console\Command;
use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Models\GenAIStat;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 詳細な使用統計分析ダッシュボード
 */
class GenAIAnalyticsCommand extends Command
{
    protected $signature = 'genai:analytics
                           {--days=30 : 分析期間（日数）}
                           {--provider= : 特定プロバイダーのみ分析}
                           {--model= : 特定モデルのみ分析}
                           {--use-case= : 特定使用ケースのみ分析}
                           {--format=table : 出力形式 (table,json,csv)}
                           {--detailed : 詳細分析を表示}
                           {--export= : 結果をファイルにエクスポート}';

    protected $description = 'Display detailed usage analytics and insights';

    public function handle(): int
    {
        $this->info('📊 GenAI Analytics Dashboard');
        $this->line('');

        $days = (int) $this->option('days');
        $startDate = now()->subDays($days);
        $endDate = now();

        $this->info("📅 Period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$days} days)");
        $this->line('');

        // 基本統計
        $this->displayBasicStats($startDate, $endDate);

        // プロバイダー分析
        $this->displayProviderAnalysis($startDate, $endDate);

        // モデル使用分析
        $this->displayModelAnalysis($startDate, $endDate);

        // 使用ケース分析
        $this->displayUseCaseAnalysis($startDate, $endDate);

        // コスト分析
        $this->displayCostAnalysis($startDate, $endDate);

        // 性能分析
        $this->displayPerformanceAnalysis($startDate, $endDate);

        // 品質分析
        $this->displayQualityAnalysis($startDate, $endDate);

        // 詳細分析
        if ($this->option('detailed')) {
            $this->displayDetailedAnalysis($startDate, $endDate);
        }

        // 廃止モデル警告
        $this->displayDeprecationWarnings($startDate, $endDate);

        // 推奨事項
        $this->displayRecommendations($startDate, $endDate);

        return 0;
    }

    /**
     * 基本統計表示
     */
    private function displayBasicStats(Carbon $startDate, Carbon $endDate): void
    {
        $stats = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_requests,
                COUNT(CASE WHEN status = "success" THEN 1 END) as successful_requests,
                COUNT(CASE WHEN status = "error" THEN 1 END) as failed_requests,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(cost) as total_cost,
                AVG(duration_ms) as avg_duration,
                COUNT(CASE WHEN is_cached = true THEN 1 END) as cached_requests,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating
            ')
            ->first();

        $successRate = $stats->total_requests > 0
            ? round(($stats->successful_requests / $stats->total_requests) * 100, 2)
            : 0;

        $cacheHitRate = $stats->total_requests > 0
            ? round(($stats->cached_requests / $stats->total_requests) * 100, 2)
            : 0;

        $this->info('📈 Basic Statistics');
        $this->table(['Metric', 'Value'], [
            ['Total Requests', number_format($stats->total_requests)],
            ['Success Rate', $successRate . '%'],
            ['Failed Requests', number_format($stats->failed_requests)],
            ['Cache Hit Rate', $cacheHitRate . '%'],
            ['Total Tokens', number_format($stats->total_tokens)],
            ['Input Tokens', number_format($stats->total_input_tokens)],
            ['Output Tokens', number_format($stats->total_output_tokens)],
            ['Total Cost', '¥' . number_format($stats->total_cost, 2)],
            ['Avg Duration', round($stats->avg_duration, 2) . 'ms'],
            ['Avg User Rating', $stats->avg_rating ? round($stats->avg_rating, 2) . '/5' : 'N/A'],
        ]);
        $this->line('');
    }

    /**
     * プロバイダー分析表示
     */
    private function displayProviderAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $query = GenAIRequest::whereBetween('created_at', [$startDate, $endDate]);

        if ($this->option('provider')) {
            $query->where('provider', $this->option('provider'));
        }

        $providerStats = $query
            ->selectRaw('
                provider,
                COUNT(*) as requests,
                SUM(cost) as total_cost,
                AVG(duration_ms) as avg_duration,
                COUNT(CASE WHEN status = "success" THEN 1 END) as successful,
                COUNT(CASE WHEN is_cached = true THEN 1 END) as cached,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating
            ')
            ->groupBy('provider')
            ->orderByDesc('requests')
            ->get();

        $this->info('🏢 Provider Analysis');
        $headers = ['Provider', 'Requests', 'Success Rate', 'Cache Rate', 'Total Cost', 'Avg Duration', 'Avg Rating'];
        $rows = [];

        foreach ($providerStats as $stat) {
            $successRate = $stat->requests > 0 ? round(($stat->successful / $stat->requests) * 100, 1) : 0;
            $cacheRate = $stat->requests > 0 ? round(($stat->cached / $stat->requests) * 100, 1) : 0;

            $rows[] = [
                $stat->provider,
                number_format($stat->requests),
                $successRate . '%',
                $cacheRate . '%',
                '¥' . number_format($stat->total_cost, 2),
                round($stat->avg_duration, 1) . 'ms',
                $stat->avg_rating ? round($stat->avg_rating, 2) : 'N/A'
            ];
        }

        $this->table($headers, $rows);
        $this->line('');
    }

    /**
     * モデル使用分析表示
     */
    private function displayModelAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $query = GenAIRequest::whereBetween('created_at', [$startDate, $endDate]);

        if ($this->option('model')) {
            $query->where('model', $this->option('model'));
        }

        if ($this->option('provider')) {
            $query->where('provider', $this->option('provider'));
        }

        $modelStats = $query
            ->selectRaw('
                provider,
                model,
                COUNT(*) as requests,
                SUM(cost) as total_cost,
                AVG(duration_ms) as avg_duration,
                COUNT(CASE WHEN is_deprecated_model = true THEN 1 END) as deprecated_usage,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating
            ')
            ->groupBy(['provider', 'model'])
            ->orderByDesc('requests')
            ->limit(10)
            ->get();

        $this->info('🤖 Top Models Analysis');
        $headers = ['Provider', 'Model', 'Requests', 'Total Cost', 'Avg Duration', 'Deprecated?', 'Avg Rating'];
        $rows = [];

        foreach ($modelStats as $stat) {
            $isDeprecated = $stat->deprecated_usage > 0 ? '⚠️ Yes' : 'No';

            $rows[] = [
                $stat->provider,
                $stat->model,
                number_format($stat->requests),
                '¥' . number_format($stat->total_cost, 2),
                round($stat->avg_duration, 1) . 'ms',
                $isDeprecated,
                $stat->avg_rating ? round($stat->avg_rating, 2) : 'N/A'
            ];
        }

        $this->table($headers, $rows);
        $this->line('');
    }

    /**
     * 使用ケース分析表示
     */
    private function displayUseCaseAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $query = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('use_case');

        if ($this->option('use-case')) {
            $query->where('use_case', $this->option('use-case'));
        }

        $useCaseStats = $query
            ->selectRaw('
                use_case,
                COUNT(*) as requests,
                SUM(cost) as total_cost,
                AVG(duration_ms) as avg_duration,
                AVG(input_tokens) as avg_input_tokens,
                AVG(output_tokens) as avg_output_tokens,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating
            ')
            ->groupBy('use_case')
            ->orderByDesc('requests')
            ->get();

        $this->info('🎯 Use Case Analysis');
        $headers = ['Use Case', 'Requests', 'Total Cost', 'Avg Input', 'Avg Output', 'Avg Rating'];
        $rows = [];

        foreach ($useCaseStats as $stat) {
            $rows[] = [
                $stat->use_case ?: 'Unknown',
                number_format($stat->requests),
                '¥' . number_format($stat->total_cost, 2),
                number_format($stat->avg_input_tokens),
                number_format($stat->avg_output_tokens),
                $stat->avg_rating ? round($stat->avg_rating, 2) : 'N/A'
            ];
        }

        $this->table($headers, $rows);
        $this->line('');
    }

    /**
     * コスト分析表示
     */
    private function displayCostAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $dailyCosts = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(cost) as daily_cost,
                COUNT(*) as daily_requests
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $this->info('💰 Cost Analysis');

        if ($dailyCosts->count() > 0) {
            $totalCost = $dailyCosts->sum('daily_cost');
            $avgDailyCost = $dailyCosts->avg('daily_cost');
            $maxDailyCost = $dailyCosts->max('daily_cost');
            $minDailyCost = $dailyCosts->min('daily_cost');

            $this->table(['Metric', 'Value'], [
                ['Total Cost', '¥' . number_format($totalCost, 2)],
                ['Avg Daily Cost', '¥' . number_format($avgDailyCost, 2)],
                ['Max Daily Cost', '¥' . number_format($maxDailyCost, 2)],
                ['Min Daily Cost', '¥' . number_format($minDailyCost, 2)],
                ['Cost per Request', '¥' . number_format($totalCost / $dailyCosts->sum('daily_requests'), 4)],
            ]);
        } else {
            $this->line('No cost data available for the specified period.');
        }

        $this->line('');
    }

    /**
     * 性能分析表示
     */
    private function displayPerformanceAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $performanceStats = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                AVG(duration_ms) as avg_duration,
                MIN(duration_ms) as min_duration,
                MAX(duration_ms) as max_duration,
                COUNT(CASE WHEN duration_ms < 1000 THEN 1 END) as fast_requests,
                COUNT(CASE WHEN duration_ms > 10000 THEN 1 END) as slow_requests,
                COUNT(*) as total_requests
            ')
            ->first();

        // 中央値とP95は別途計算
        $durations = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->pluck('duration_ms')
            ->sort()
            ->values();

        $median = $durations->count() > 0 ? $durations->slice($durations->count() * 0.5, 1)->first() : 0;
        $p95 = $durations->count() > 0 ? $durations->slice($durations->count() * 0.95, 1)->first() : 0;

        $this->info('⚡ Performance Analysis');

        $fastPercentage = $performanceStats->total_requests > 0
            ? round(($performanceStats->fast_requests / $performanceStats->total_requests) * 100, 1)
            : 0;

        $slowPercentage = $performanceStats->total_requests > 0
            ? round(($performanceStats->slow_requests / $performanceStats->total_requests) * 100, 1)
            : 0;

        $this->table(['Metric', 'Value'], [
            ['Avg Duration', round($performanceStats->avg_duration, 2) . 'ms'],
            ['Min Duration', round($performanceStats->min_duration, 2) . 'ms'],
            ['Max Duration', round($performanceStats->max_duration, 2) . 'ms'],
            ['Median Duration', round($median, 2) . 'ms'],
            ['95th Percentile', round($p95, 2) . 'ms'],
            ['Fast Requests (<1s)', $fastPercentage . '%'],
            ['Slow Requests (>10s)', $slowPercentage . '%'],
        ]);
        $this->line('');
    }

    /**
     * 品質分析表示
     */
    private function displayQualityAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $qualityStats = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('user_rating')
            ->selectRaw('
                COUNT(*) as rated_requests,
                AVG(user_rating) as avg_rating,
                COUNT(CASE WHEN user_rating >= 4 THEN 1 END) as excellent_requests,
                COUNT(CASE WHEN user_rating >= 3 THEN 1 END) as good_requests,
                COUNT(CASE WHEN user_rating < 3 THEN 1 END) as poor_requests
            ')
            ->first();

        $this->info('⭐ Quality Analysis');

        if ($qualityStats && $qualityStats->rated_requests > 0) {
            $excellentPercentage = round(($qualityStats->excellent_requests / $qualityStats->rated_requests) * 100, 1);
            $goodPercentage = round(($qualityStats->good_requests / $qualityStats->rated_requests) * 100, 1);

            $this->table(['Metric', 'Value'], [
                ['Total Rated Requests', number_format($qualityStats->rated_requests)],
                ['Average Rating', round($qualityStats->avg_rating, 2) . '/5'],
                ['Excellent (4-5)', $excellentPercentage . '%'],
                ['Good (3+)', $goodPercentage . '%'],
            ]);
        } else {
            $this->line('No quality ratings available for the specified period.');
        }

        $this->line('');
    }

    /**
     * 詳細分析表示
     */
    private function displayDetailedAnalysis(Carbon $startDate, Carbon $endDate): void
    {
        $this->info('🔍 Detailed Analysis');

        // プリセット使用分析
        $presetStats = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('preset_name')
            ->selectRaw('
                preset_name,
                COUNT(*) as usage_count,
                AVG(user_rating) as avg_rating,
                SUM(cost) as total_cost
            ')
            ->groupBy('preset_name')
            ->orderByDesc('usage_count')
            ->limit(5)
            ->get();

        if ($presetStats->count() > 0) {
            $this->line('📋 Top Presets:');
            foreach ($presetStats as $preset) {
                $this->line("  • {$preset->preset_name}: {$preset->usage_count} uses, ¥{$preset->total_cost}, Rating: " . round($preset->avg_rating ?? 0, 2));
            }
            $this->line('');
        }

        // 時間帯分析
        $hourlyStats = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as requests,
                AVG(duration_ms) as avg_duration
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        if ($hourlyStats->count() > 0) {
            $this->line('🕐 Peak Hours:');
            $topHours = $hourlyStats->sortByDesc('requests')->take(3);
            foreach ($topHours as $hour) {
                $this->line("  • {$hour->hour}:00 - {$hour->requests} requests (avg {$hour->avg_duration}ms)");
            }
            $this->line('');
        }
    }

    /**
     * 廃止モデル警告表示
     */
    private function displayDeprecationWarnings(Carbon $startDate, Carbon $endDate): void
    {
        $deprecatedUsage = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->where('is_deprecated_model', true)
            ->selectRaw('
                provider,
                model,
                COUNT(*) as usage_count,
                SUM(cost) as total_cost,
                replacement_suggestion
            ')
            ->groupBy(['provider', 'model', 'replacement_suggestion'])
            ->orderByDesc('usage_count')
            ->get();

        if ($deprecatedUsage->count() > 0) {
            $this->warn('⚠️  Deprecated Model Usage');
            foreach ($deprecatedUsage as $usage) {
                $this->line("  🚨 {$usage->provider}/{$usage->model}: {$usage->usage_count} uses, ¥{$usage->total_cost}");
                if ($usage->replacement_suggestion) {
                    $this->line("     💡 Suggested replacement: {$usage->replacement_suggestion}");
                }
            }
            $this->line('');
        }
    }

    /**
     * 推奨事項表示
     */
    private function displayRecommendations(Carbon $startDate, Carbon $endDate): void
    {
        $this->info('💡 Recommendations');

        $recommendations = [];

        // コスト最適化
        $costPerToken = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                provider,
                model,
                SUM(cost) / SUM(total_tokens) as cost_per_token,
                COUNT(*) as usage_count
            ')
            ->groupBy(['provider', 'model'])
            ->having('usage_count', '>', 10)
            ->orderBy('cost_per_token')
            ->first();

        if ($costPerToken) {
            $recommendations[] = "💰 Most cost-efficient model: {$costPerToken->provider}/{$costPerToken->model} (¥" . round($costPerToken->cost_per_token, 6) . " per token)";
        }

        // 性能最適化
        $fastestModel = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                provider,
                model,
                AVG(duration_ms) as avg_duration,
                COUNT(*) as usage_count
            ')
            ->groupBy(['provider', 'model'])
            ->having('usage_count', '>', 10)
            ->orderBy('avg_duration')
            ->first();

        if ($fastestModel) {
            $recommendations[] = "⚡ Fastest model: {$fastestModel->provider}/{$fastestModel->model} ({$fastestModel->avg_duration}ms avg)";
        }

        // キャッシュ効率
        $cacheHitRate = GenAIRequest::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(CASE WHEN is_cached = true THEN 1 END) / COUNT(*) * 100 as cache_rate
            ')
            ->first();

        if ($cacheHitRate && $cacheHitRate->cache_rate < 20) {
            $recommendations[] = "📈 Low cache hit rate ({$cacheHitRate->cache_rate}%). Consider enabling caching for frequently used prompts.";
        }

        foreach ($recommendations as $recommendation) {
            $this->line("  • {$recommendation}");
        }

        if (empty($recommendations)) {
            $this->line('  No specific recommendations at this time.');
        }

        $this->line('');
    }
}
