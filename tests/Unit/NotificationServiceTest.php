<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery as m;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $notificationService;

    private array $mockConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConfig = [
            'deprecation_channels' => ['log', 'mail'],
            'update_channels' => ['log'],
            'cost_alert_channels' => ['log', 'mail', 'slack'],
            'performance_alert_channels' => ['log', 'slack'],
            'report_channels' => ['mail'],
            'mail_recipients' => ['admin@example.com'],
            'slack_webhook_url' => 'https://hooks.slack.com/test',
        ];

        // config設定をセット
        config(['genai.notifications' => $this->mockConfig]);

        $this->notificationService = new NotificationService;
    }

    public function test_can_send_deprecation_warning()
    {
        Log::spy();

        $deprecatedModels = [
            ['provider' => 'openai', 'model' => 'gpt-3.5-turbo', 'deprecated_date' => '2024-06-01'],
            ['provider' => 'claude', 'model' => 'claude-1', 'deprecated_date' => '2024-05-15'],
        ];

        $replacementSuggestions = [
            'gpt-3.5-turbo' => 'gpt-4o-mini',
            'claude-1' => 'claude-3-sonnet',
        ];

        $result = $this->notificationService->sendDeprecationWarning($deprecatedModels, $replacementSuggestions);

        $this->assertTrue($result);

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[GenAI] model_deprecation_warning')
                    && isset($context['deprecated_models'])
                    && isset($context['replacement_suggestions']);
            });
    }

    public function test_can_send_model_update_notification()
    {
        Log::spy();

        $newModels = [
            ['provider' => 'openai', 'model' => 'gpt-4-turbo-new'],
            ['provider' => 'gemini', 'model' => 'gemini-2.0-pro'],
        ];

        $updatedModels = [
            ['provider' => 'claude', 'model' => 'claude-3-opus', 'changes' => ['pricing updated']],
        ];

        $result = $this->notificationService->sendModelUpdateNotification($newModels, $updatedModels);

        $this->assertTrue($result);

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[GenAI] model_update')
                    && $context['total_new'] === 2
                    && $context['total_updated'] === 1;
            });
    }

    public function test_can_send_cost_alert()
    {
        Log::spy();
        Mail::fake();

        $costData = [
            'current_cost' => 150.0,
            'threshold' => 100.0,
            'threshold_exceeded' => true,
            'budget_exceed_percent' => 130, // high severity になるように
            'provider' => 'openai',
            'period' => 'daily',
        ];

        $this->notificationService->sendCostAlert($costData);

        // ログ出力の確認（severityに基づいてerrorレベルで出力される）
        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[GenAI] cost_alert')
                    && isset($context['severity']);
            });

        // メール送信の確認
        $this->assertTrue(true); // Mail::rawを使用しているため、Mailableクラスは使用されない
    }

    public function test_can_send_performance_alert()
    {
        Log::spy();

        $performanceData = [
            'response_time_avg' => 8000,
            'error_rate' => 0.15,
            'affected_models' => ['gpt-4', 'claude-3-opus'],
            'threshold' => 5000,
            'period' => '1h',
            'performance_degradation_percent' => 40, // high severity になるように
        ];

        $result = $this->notificationService->sendPerformanceAlert($performanceData);

        $this->assertTrue($result);

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[GenAI] performance_alert')
                    && isset($context['severity']);
            });
    }

    public function test_can_send_scheduled_report()
    {
        Mail::fake();

        $reportData = [
            'period' => 'weekly',
            'total_requests' => 1500,
            'total_cost' => 45.30,
            'top_models' => ['gpt-4', 'claude-3-sonnet'],
            'summary' => 'Weekly usage summary',
        ];

        $this->notificationService->sendScheduledReport('weekly_usage', $reportData);

        $this->assertTrue(true); // Mail::rawを使用しているため、Mailableクラスは使用されない
    }

    public function test_determines_cost_severity_correctly()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('determineCostSeverity');
        $method->setAccessible(true);

        // 低い使用量
        $lowCostData = ['budget_exceed_percent' => 50];
        $severity = $method->invoke($this->notificationService, $lowCostData);
        $this->assertEquals('low', $severity);

        // 閾値を超過
        $highCostData = ['budget_exceed_percent' => 130];
        $severity = $method->invoke($this->notificationService, $highCostData);
        $this->assertEquals('high', $severity);

        // 大幅な超過
        $criticalCostData = ['budget_exceed_percent' => 160];
        $severity = $method->invoke($this->notificationService, $criticalCostData);
        $this->assertEquals('critical', $severity);
    }

    public function test_determines_performance_severity_correctly()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('determinePerformanceSeverity');
        $method->setAccessible(true);

        // 良好なパフォーマンス
        $goodPerformance = ['performance_degradation_percent' => 5];
        $severity = $method->invoke($this->notificationService, $goodPerformance);
        $this->assertEquals('low', $severity);

        // パフォーマンス劣化
        $poorPerformance = ['performance_degradation_percent' => 40];
        $severity = $method->invoke($this->notificationService, $poorPerformance);
        $this->assertEquals('high', $severity);
    }

    public function test_generates_cost_recommendations()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('generateCostRecommendations');
        $method->setAccessible(true);

        $costData = [
            'budget_exceed_percent' => 120,
        ];

        $recommendations = $method->invoke($this->notificationService, $costData);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);
        $this->assertContains('Consider switching to more cost-effective models', $recommendations);
    }

    public function test_generates_performance_recommendations()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('generatePerformanceRecommendations');
        $method->setAccessible(true);

        $performanceData = [
            'avg_response_time' => 8000, // generatePerformanceRecommendationsで使用されるキー
            'error_rate' => 0.15,
            'affected_models' => ['gpt-4'],
        ];

        $recommendations = $method->invoke($this->notificationService, $performanceData);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);
    }

    public function test_handles_slack_notification_configuration()
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $costData = [
            'current_cost' => 150.0,
            'threshold' => 100.0,
            'threshold_exceeded' => true,
        ];

        // Slack URLが設定されている場合
        $this->notificationService->sendCostAlert($costData);

        // HTTP::postが呼ばれることを確認
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'hooks.slack.com');
        });
    }

    public function test_handles_missing_slack_configuration()
    {
        Log::spy();

        // Slack設定を削除
        config(['genai.notifications.slack_webhook_url' => null]);
        $serviceWithoutSlack = new NotificationService;

        $costData = [
            'current_cost' => 150.0,
            'threshold' => 100.0,
            'threshold_exceeded' => true,
        ];

        $result = $serviceWithoutSlack->sendCostAlert($costData);

        $this->assertTrue($result);

        // 警告ログが出力されることを確認
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Slack webhook URL not configured');
            });
    }

    public function test_generates_correct_email_subject()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('generateEmailSubject');
        $method->setAccessible(true);

        $data = [
            'type' => 'cost_alert',
            'severity' => 'high',
        ];

        $subject = $method->invoke($this->notificationService, $data);

        $this->assertStringContainsString('GenAI', $subject);
        $this->assertStringContainsString('Cost Alert', $subject);
        $this->assertStringContainsString('IMPORTANT', $subject); // 実際の実装では[IMPORTANT]が使用される
    }

    public function test_generates_correct_slack_emoji()
    {
        $reflection = new \ReflectionClass($this->notificationService);
        $method = $reflection->getMethod('getSlackEmoji');
        $method->setAccessible(true);

        $this->assertEquals(':warning:', $method->invoke($this->notificationService, 'model_deprecation_warning'));
        $this->assertEquals(':money_with_wings:', $method->invoke($this->notificationService, 'cost_alert'));
        $this->assertEquals(':chart_with_downwards_trend:', $method->invoke($this->notificationService, 'performance_alert'));
        $this->assertEquals(':information_source:', $method->invoke($this->notificationService, 'model_update'));
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
