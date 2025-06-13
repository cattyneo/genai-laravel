<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenAIStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:stats
                            {--days=7 : Number of days to show stats for}
                            {--provider= : Filter by specific provider}
                            {--detailed : Show detailed breakdown}';

    /**
     * The console command description.
     */
    protected $description = 'Display GenAI usage statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $provider = $this->option('provider');
        $detailed = $this->option('detailed');

        $this->info("📊 GenAI Usage Statistics (Last {$days} days)");
        $this->newLine();

        // 基本統計
        $this->displayBasicStats($days, $provider);

        // プロバイダー別統計
        $this->displayProviderStats($days, $provider);

        // モデル別統計
        $this->displayModelStats($days, $provider);

        // コスト統計
        $this->displayCostStats($days, $provider);

        // 詳細統計
        if ($detailed) {
            $this->displayDetailedStats($days, $provider);
        }

        return Command::SUCCESS;
    }

    /**
     * 基本統計を表示
     */
    private function displayBasicStats(int $days, ?string $provider): void
    {
        $query = GenAIRequest::where('created_at', '>=', now()->subDays($days));

        if ($provider) {
            $query->where('provider', $provider);
        }

        $totalRequests = $query->count();
        $successfulRequests = $query->whereNotNull('response_content')->count();
        $totalTokens = $query->sum('input_tokens') + $query->sum('output_tokens');
        $totalCost = $query->sum('cost');

        $successRate = $totalRequests > 0 ? round(($successfulRequests / $totalRequests) * 100, 2) : 0;

        $this->line('📈 Basic Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Requests', number_format($totalRequests)],
                ['Successful Requests', number_format($successfulRequests)],
                ['Success Rate', $successRate.'%'],
                ['Total Tokens', number_format($totalTokens)],
                ['Total Cost', '$'.number_format($totalCost, 4)],
            ]
        );

        $this->newLine();
    }

    /**
     * プロバイダー別統計を表示
     */
    private function displayProviderStats(int $days, ?string $providerFilter): void
    {
        $query = GenAIRequest::select('provider')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(input_tokens + output_tokens) as total_tokens')
            ->selectRaw('SUM(cost) as total_cost')
            ->selectRaw('AVG(CASE WHEN duration_ms IS NOT NULL THEN duration_ms ELSE 0 END) as avg_response_time_ms')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('response_content');

        if ($providerFilter) {
            $query->where('provider', $providerFilter);
        }

        $stats = $query->groupBy('provider')->get();

        if ($stats->isEmpty()) {
            $this->warn('No data available for the specified period.');

            return;
        }

        $this->line('🏢 Provider Statistics:');
        $this->table(
            ['Provider', 'Requests', 'Tokens', 'Cost', 'Avg Response Time (ms)'],
            $stats->map(function ($stat) {
                return [
                    ucfirst($stat->provider),
                    number_format($stat->request_count),
                    number_format($stat->total_tokens),
                    '$'.number_format($stat->total_cost, 4),
                    number_format($stat->avg_response_time_ms ?? 0, 2),
                ];
            })->toArray()
        );

        $this->newLine();
    }

    /**
     * モデル別統計を表示
     */
    private function displayModelStats(int $days, ?string $provider): void
    {
        $query = GenAIRequest::select('provider', 'model')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(cost) as total_cost')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('response_content');

        if ($provider) {
            $query->where('provider', $provider);
        }

        $stats = $query->groupBy('provider', 'model')
            ->orderByDesc('request_count')
            ->limit(10)
            ->get();

        if ($stats->isEmpty()) {
            return;
        }

        $this->line('🤖 Top Models:');
        $this->table(
            ['Provider', 'Model', 'Requests', 'Cost'],
            $stats->map(function ($stat) {
                return [
                    ucfirst($stat->provider),
                    $stat->model,
                    number_format($stat->request_count),
                    '$'.number_format($stat->total_cost, 4),
                ];
            })->toArray()
        );

        $this->newLine();
    }

    /**
     * コスト統計を表示
     */
    private function displayCostStats(int $days, ?string $provider): void
    {
        $query = GenAIRequest::where('created_at', '>=', now()->subDays($days));

        if ($provider) {
            $query->where('provider', $provider);
        }

        $costStats = $query->selectRaw('
            SUM(cost) as total_cost,
            AVG(cost) as avg_cost_per_request,
            MIN(cost) as min_cost,
            MAX(cost) as max_cost
        ')->first();

        if (! $costStats || $costStats->total_cost === null) {
            return;
        }

        // 日別コスト推移
        $dailyCosts = GenAIRequest::selectRaw('DATE(created_at) as date, SUM(cost) as daily_cost')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $this->line('💰 Cost Analysis:');
        $this->table(
            ['Metric', 'Amount'],
            [
                ['Total Cost', '$'.number_format($costStats->total_cost, 4)],
                ['Average per Request', '$'.number_format($costStats->avg_cost_per_request, 6)],
                ['Minimum Cost', '$'.number_format($costStats->min_cost, 6)],
                ['Maximum Cost', '$'.number_format($costStats->max_cost, 6)],
                ['Daily Average', '$'.number_format($costStats->total_cost / $days, 4)],
            ]
        );

        $this->newLine();

        if ($dailyCosts->isNotEmpty()) {
            $this->line('📅 Daily Cost Breakdown:');
            $this->table(
                ['Date', 'Cost'],
                $dailyCosts->map(function ($day) {
                    return [
                        Carbon::parse($day->date)->format('Y-m-d'),
                        '$'.number_format($day->daily_cost, 4),
                    ];
                })->toArray()
            );

            $this->newLine();
        }
    }

    /**
     * 詳細統計を表示
     */
    private function displayDetailedStats(int $days, ?string $provider): void
    {
        $this->line('🔍 Detailed Statistics:');

        // エラー統計
        $errorQuery = GenAIRequest::where('created_at', '>=', now()->subDays($days))
            ->whereNull('response_content');

        if ($provider) {
            $errorQuery->where('provider', $provider);
        }

        $errorCount = $errorQuery->count();
        $commonErrors = $errorQuery->select('error_message')
            ->selectRaw('COUNT(*) as error_count')
            ->whereNotNull('error_message')
            ->groupBy('error_message')
            ->orderByDesc('error_count')
            ->limit(5)
            ->get();

        if ($errorCount > 0) {
            $this->line("❌ Errors: {$errorCount}");

            if ($commonErrors->isNotEmpty()) {
                $this->table(
                    ['Error Message', 'Count'],
                    $commonErrors->map(function ($error) {
                        return [
                            substr($error->error_message, 0, 50).'...',
                            $error->error_count,
                        ];
                    })->toArray()
                );
            }

            $this->newLine();
        }

        // レスポンス時間統計
        $responseTimeStats = GenAIRequest::where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('response_content')
            ->whereNotNull('duration_ms')
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->selectRaw('
                AVG(duration_ms) as avg_response_time,
                MIN(duration_ms) as min_response_time,
                MAX(duration_ms) as max_response_time
            ')
            ->first();

        if ($responseTimeStats && $responseTimeStats->avg_response_time) {
            $this->line('⏱️  Response Time Analysis:');
            $this->table(
                ['Metric', 'Time (ms)'],
                [
                    ['Average', number_format($responseTimeStats->avg_response_time, 2)],
                    ['Minimum', number_format($responseTimeStats->min_response_time, 2)],
                    ['Maximum', number_format($responseTimeStats->max_response_time, 2)],
                ]
            );
        }
    }
}
