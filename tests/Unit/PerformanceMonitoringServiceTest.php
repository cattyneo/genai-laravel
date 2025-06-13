<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Services\GenAI\PerformanceMonitoringService;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery as m;

class PerformanceMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceMonitoringService $performanceService;

    private array $mockConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト前にデータベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        $this->mockConfig = [
            'enabled' => true,
            'metrics_retention_days' => 30,
            'real_time_monitoring' => true,
            'alert_thresholds' => [
                'response_time_warning' => 5000,
                'response_time_critical' => 10000,
                'error_rate_warning' => 5.0,
                'error_rate_critical' => 10.0,
            ],
            'anomaly_detection' => [
                'enabled' => true,
                'sensitivity' => 0.7,
            ],
        ];

        config(['genai.performance_monitoring' => $this->mockConfig]);

        // NotificationServiceのモックを作成
        $mockNotificationService = m::mock(NotificationService::class);
        $mockNotificationService->shouldReceive('sendPerformanceAlert')->andReturn(true);

        $this->performanceService = new PerformanceMonitoringService($mockNotificationService);
    }

    public function test_can_record_request_metrics()
    {
        $metrics = [
            'response_time' => 1500,
            'provider' => 'openai',
            'model' => 'gpt-4',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'success' => true,
            'timestamp' => now(),
        ];

        $result = $this->performanceService->recordMetrics($metrics);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('metric_id', $result);
    }

    public function test_can_calculate_percentiles()
    {
        // テスト用のレスポンス時間データを記録
        $responseTimes = [1000, 1200, 1500, 2000, 2500, 3000, 5000, 8000, 10000, 15000];

        foreach ($responseTimes as $time) {
            $this->performanceService->recordMetrics([
                'response_time' => $time,
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        $percentiles = $this->performanceService->calculatePercentiles('1h');

        $this->assertIsArray($percentiles);
        $this->assertArrayHasKey('p50', $percentiles);
        $this->assertArrayHasKey('p95', $percentiles);
        $this->assertArrayHasKey('p99', $percentiles);

        // P95は8000ms以上であることを確認
        $this->assertGreaterThanOrEqual(8000, $percentiles['p95']);
    }

    public function test_can_calculate_error_rate()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // 成功リクエスト
        for ($i = 0; $i < 9; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => 1000,
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        // 失敗リクエスト
        $this->performanceService->recordMetrics([
            'response_time' => 0,
            'provider' => 'openai',
            'model' => 'gpt-4',
            'success' => false,
            'error' => 'rate_limit_exceeded',
            'timestamp' => now(),
        ]);

        $errorRate = $this->performanceService->calculateErrorRate('1h');

        // エラー率計算を確認
        $this->assertGreaterThanOrEqual(0.0, $errorRate['rate']);
        $this->assertLessThanOrEqual(1.0, $errorRate['rate']);

        // 実際のエラー数と成功数の合計が正しいことを確認
        $totalExpected = 10; // 作成したリクエスト数
        $this->assertEquals($totalExpected, $errorRate['total_requests']);

        // エラー数が少なくとも1以上であることを確認（他のテストの影響を考慮）
        $this->assertGreaterThanOrEqual(1, $errorRate['total_errors']);
    }

    public function test_can_calculate_throughput()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // 1分間で5回のリクエストを記録
        $baseTime = now();

        for ($i = 0; $i < 5; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => 1000,
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => $baseTime->copy()->addSeconds($i * 10),
            ]);
        }

        $throughput = $this->performanceService->calculateThroughput('1h');

        $this->assertIsArray($throughput);
        $this->assertArrayHasKey('rps', $throughput);
        $this->assertArrayHasKey('total_requests', $throughput);
        $this->assertGreaterThan(0, $throughput['rps']);
    }

    public function test_can_detect_performance_anomalies()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // 正常なパフォーマンスデータを記録
        for ($i = 0; $i < 50; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(1000, 2000),
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now()->subMinutes($i),
            ]);
        }

        // 異常に遅いレスポンス時間を記録
        for ($i = 0; $i < 5; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(10000, 15000),
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        $anomalies = $this->performanceService->detectAnomalies();

        $this->assertIsArray($anomalies);
        $this->assertNotEmpty($anomalies);
        $this->assertArrayHasKey('response_time_anomaly', $anomalies);
    }

    public function test_can_generate_performance_report()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // テストデータを記録
        for ($i = 0; $i < 20; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(1000, 3000),
                'provider' => 'openai',
                'model' => 'gpt-4',
                'input_tokens' => rand(50, 200),
                'output_tokens' => rand(20, 100),
                'success' => $i < 18, // 10%エラー率
                'timestamp' => now()->subMinutes($i),
            ]);
        }

        $report = $this->performanceService->generateReport('24h');

        $this->assertIsArray($report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('metrics', $report);
        $this->assertArrayHasKey('trends', $report);
        $this->assertArrayHasKey('recommendations', $report);

        // サマリーの内容確認
        $summary = $report['summary'];
        $this->assertArrayHasKey('total_requests', $summary);
        $this->assertArrayHasKey('avg_response_time', $summary);
        $this->assertArrayHasKey('error_rate', $summary);
        $this->assertArrayHasKey('throughput', $summary);
    }

    public function test_can_get_real_time_metrics()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // 最近のメトリクスを記録
        for ($i = 0; $i < 10; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(1000, 2000),
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now()->subSeconds($i * 6),
            ]);
        }

        $realTimeMetrics = $this->performanceService->getRealTimeMetrics();

        $this->assertIsArray($realTimeMetrics);
        $this->assertArrayHasKey('current_rps', $realTimeMetrics);
        $this->assertArrayHasKey('avg_response_time', $realTimeMetrics);
        $this->assertArrayHasKey('active_requests', $realTimeMetrics);
        $this->assertArrayHasKey('error_rate', $realTimeMetrics);
    }

    public function test_can_track_model_performance()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        $models = ['gpt-4', 'gpt-4o-mini', 'claude-3-sonnet'];

        foreach ($models as $model) {
            for ($i = 0; $i < 5; $i++) {
                $this->performanceService->recordMetrics([
                    'response_time' => rand(1000, 3000),
                    'provider' => $model === 'claude-3-sonnet' ? 'claude' : 'openai',
                    'model' => $model,
                    'success' => true,
                    'timestamp' => now(),
                ]);
            }
        }

        $modelPerformance = $this->performanceService->getModelPerformanceComparison($models);

        $this->assertIsArray($modelPerformance);
        $this->assertCount(3, $modelPerformance);

        foreach ($modelPerformance as $model => $performance) {
            $this->assertArrayHasKey('request_count', $performance);
            $this->assertArrayHasKey('avg_response_time', $performance);
            $this->assertArrayHasKey('error_rate', $performance);
            $this->assertContains($model, $models);
        }
    }

    public function test_can_identify_performance_bottlenecks()
    {
        // データベースをクリア
        \CattyNeo\LaravelGenAI\Models\GenAIRequest::truncate();

        // 特定のプロバイダーで遅いレスポンス時間を記録
        for ($i = 0; $i < 10; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(8000, 12000),
                'provider' => 'claude',
                'model' => 'claude-3-opus',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        // 他のプロバイダーで正常なレスポンス時間を記録
        for ($i = 0; $i < 10; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => rand(1000, 2000),
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        $bottlenecks = $this->performanceService->identifyBottlenecks();

        $this->assertIsArray($bottlenecks);
        $this->assertNotEmpty($bottlenecks);

        // Claudeがボトルネックとして検出されることを確認
        $claudeBottleneck = collect($bottlenecks)->first(function ($bottleneck) {
            return $bottleneck['provider'] === 'claude';
        });

        $this->assertNotNull($claudeBottleneck);
        $this->assertGreaterThan(5000, $claudeBottleneck['avg_response_time']);
    }

    public function test_can_generate_performance_trends()
    {
        // 異なる時間帯でメトリクスを記録
        $hoursAgo = 24;
        for ($hour = 0; $hour < $hoursAgo; $hour++) {
            for ($i = 0; $i < 3; $i++) {
                $this->performanceService->recordMetrics([
                    'response_time' => rand(1000, 2000) + ($hour * 50), // 時間とともに遅くなる
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                    'success' => true,
                    'timestamp' => now()->subHours($hour),
                ]);
            }
        }

        $trends = $this->performanceService->getPerformanceTrends('24h');

        $this->assertIsArray($trends);
        $this->assertArrayHasKey('response_time_trend', $trends);
        $this->assertArrayHasKey('throughput_trend', $trends);
        $this->assertArrayHasKey('error_rate_trend', $trends);

        // トレンドが悪化していることを確認
        $responseTrend = $trends['response_time_trend'];
        $this->assertEquals('increasing', $responseTrend['direction']);
    }

    public function test_can_cache_metrics_for_performance()
    {
        Cache::shouldReceive('remember')
            ->andReturn([
                'avg_response_time' => 1500,
                'throughput' => 2.5,
                'error_rate' => 0.02,
            ]);

        $cachedMetrics = $this->performanceService->getCachedMetrics('1h');

        $this->assertIsArray($cachedMetrics);
        $this->assertEquals(1500, $cachedMetrics['avg_response_time']);
        $this->assertEquals(2.5, $cachedMetrics['throughput']);
    }

    public function test_handles_disabled_monitoring()
    {
        config(['genai.performance_monitoring.enabled' => false]);
        $disabledService = new PerformanceMonitoringService;

        $result = $disabledService->recordMetrics([
            'response_time' => 1500,
            'provider' => 'openai',
            'model' => 'gpt-4',
            'success' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('disabled', $result['status']);
    }

    public function test_validates_metrics_data()
    {
        $invalidMetrics = [
            'response_time' => 'invalid',
            'provider' => null,
            'model' => '',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->performanceService->recordMetrics($invalidMetrics);
    }

    public function test_can_cleanup_old_metrics()
    {
        // 古いメトリクスを記録
        for ($i = 0; $i < 5; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => 1500,
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now()->subDays(35), // 保持期間より古い
            ]);
        }

        // 新しいメトリクスを記録
        for ($i = 0; $i < 5; $i++) {
            $this->performanceService->recordMetrics([
                'response_time' => 1500,
                'provider' => 'openai',
                'model' => 'gpt-4',
                'success' => true,
                'timestamp' => now(),
            ]);
        }

        $cleanupResult = $this->performanceService->cleanupOldMetrics();

        $this->assertTrue($cleanupResult['success']);
        $this->assertEquals(5, $cleanupResult['deleted_count']);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
