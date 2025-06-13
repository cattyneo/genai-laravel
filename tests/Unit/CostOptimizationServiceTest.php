<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Models\GenAIRequest;
use CattyNeo\LaravelGenAI\Services\GenAI\CostOptimizationService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;

class CostOptimizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CostOptimizationService $costService;

    private array $mockConfig;

    private $mockReplacementService;

    private $mockNotificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConfig = [
            'enabled' => true,
            'budget_limits' => [
                'daily' => 100.0,
                'weekly' => 500.0,
                'monthly' => 2000.0,
            ],
            'optimization_targets' => [
                'cost_reduction_percentage' => 20,
                'maintain_quality_threshold' => 0.9,
            ],
            'alert_thresholds' => [
                'budget_warning' => 0.8,
                'budget_critical' => 0.95,
            ],
        ];

        config(['genai.cost_optimization' => $this->mockConfig]);

        // 依存関係をモック
        $this->mockReplacementService = m::mock(ModelReplacementService::class);
        $this->mockNotificationService = m::mock(NotificationService::class);

        $this->costService = new CostOptimizationService(
            $this->mockReplacementService,
            $this->mockNotificationService
        );
    }

    public function test_can_analyze_cost_trends()
    {
        $this->createTestRequests();

        $trends = $this->costService->analyzeCostTrends('7d');

        $this->assertIsArray($trends);
        $this->assertArrayHasKey('total_cost', $trends);
        $this->assertArrayHasKey('daily_average', $trends);
        $this->assertArrayHasKey('trend_direction', $trends);
        $this->assertArrayHasKey('cost_by_provider', $trends);
    }

    public function test_can_identify_expensive_models()
    {
        $this->createTestRequests();

        $expensiveModels = $this->costService->identifyExpensiveModels();

        $this->assertIsArray($expensiveModels);
        $this->assertNotEmpty($expensiveModels);

        foreach ($expensiveModels as $model) {
            $this->assertArrayHasKey('provider', $model);
            $this->assertArrayHasKey('model', $model);
            $this->assertArrayHasKey('total_cost', $model);
            $this->assertArrayHasKey('avg_cost_per_request', $model);
            $this->assertArrayHasKey('optimization_potential', $model);
        }
    }

    public function test_can_generate_optimization_suggestions()
    {
        $this->createTestRequests();

        // ModelReplacementServiceのモック期待値を設定
        $this->mockReplacementService->shouldReceive('findReplacements')
            ->andReturn([
                [
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'performance_score' => 0.9,
                    'cost_efficiency' => 0.8,
                ],
            ]);

        $suggestions = $this->costService->generateOptimizationSuggestions();

        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);

        foreach ($suggestions as $suggestion) {
            $this->assertArrayHasKey('type', $suggestion);
            $this->assertArrayHasKey('description', $suggestion);
            $this->assertArrayHasKey('potential_savings', $suggestion);
            $this->assertArrayHasKey('implementation_effort', $suggestion);
        }
    }

    public function test_can_calculate_potential_savings()
    {
        $this->createTestRequests();

        $currentModel = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'avg_cost_per_token' => 0.00003,
        ];

        $alternativeModel = [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'avg_cost_per_token' => 0.00001,
        ];

        $monthlyTokens = 1000000;

        $savings = $this->costService->calculatePotentialSavings(
            $currentModel,
            $alternativeModel,
            $monthlyTokens
        );

        $this->assertIsArray($savings);
        $this->assertArrayHasKey('current_cost', $savings);
        $this->assertArrayHasKey('alternative_cost', $savings);
        $this->assertArrayHasKey('potential_savings', $savings);
        $this->assertArrayHasKey('savings_percentage', $savings);

        $expectedSavings = ($currentModel['avg_cost_per_token'] - $alternativeModel['avg_cost_per_token']) * $monthlyTokens;
        $this->assertEquals($expectedSavings, $savings['potential_savings']);
    }

    public function test_can_check_budget_status()
    {
        $this->createTestRequests();

        $budgetStatus = $this->costService->checkBudgetStatus('daily');

        $this->assertIsArray($budgetStatus);
        $this->assertArrayHasKey('period', $budgetStatus);
        $this->assertArrayHasKey('limit', $budgetStatus);
        $this->assertArrayHasKey('current_usage', $budgetStatus);
        $this->assertArrayHasKey('remaining', $budgetStatus);
        $this->assertArrayHasKey('usage_percentage', $budgetStatus);
        $this->assertArrayHasKey('status', $budgetStatus);
    }

    public function test_can_generate_budget_alerts()
    {
        // 予算の80%以上使用する高コストリクエストを作成
        $this->createHighCostRequests();

        $alerts = $this->costService->generateBudgetAlerts();

        $this->assertIsArray($alerts);

        if (! empty($alerts)) {
            foreach ($alerts as $alert) {
                $this->assertArrayHasKey('type', $alert);
                $this->assertArrayHasKey('period', $alert);
                $this->assertArrayHasKey('severity', $alert);
                $this->assertArrayHasKey('current_usage', $alert);
                $this->assertArrayHasKey('limit', $alert);
            }
        }
    }

    public function test_can_generate_cost_report()
    {
        $this->createTestRequests();

        $report = $this->costService->generateCostReport('monthly');

        $this->assertIsArray($report);
        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('breakdown', $report);
        $this->assertArrayHasKey('trends', $report);
        $this->assertArrayHasKey('recommendations', $report);

        // サマリーの内容確認
        $summary = $report['summary'];
        $this->assertArrayHasKey('total_cost', $summary);
        $this->assertArrayHasKey('total_requests', $summary);
        $this->assertArrayHasKey('avg_cost_per_request', $summary);
        $this->assertArrayHasKey('top_providers', $summary);
    }

    public function test_can_recommend_alternative_models()
    {
        $currentUsage = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'monthly_requests' => 1000,
            'avg_tokens_per_request' => 500,
            'current_monthly_cost' => 50.0,
        ];

        // ModelReplacementServiceのモック期待値を設定
        $this->mockReplacementService->shouldReceive('findReplacements')
            ->with('gpt-4', 'openai', ['use_case' => 'cost_optimization'])
            ->andReturn([
                [
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'performance_score' => 0.9,
                ],
            ]);

        $recommendations = $this->costService->recommendAlternativeModels($currentUsage);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $recommendation) {
            $this->assertArrayHasKey('alternative_provider', $recommendation);
            $this->assertArrayHasKey('alternative_model', $recommendation);
            $this->assertArrayHasKey('cost_savings', $recommendation);
            $this->assertArrayHasKey('quality_score', $recommendation);
            $this->assertArrayHasKey('recommendation_reason', $recommendation);
        }
    }

    public function test_can_optimize_request_frequency()
    {
        $this->createDuplicateRequests();

        $optimizations = $this->costService->optimizeRequestFrequency();

        $this->assertIsArray($optimizations);
        $this->assertArrayHasKey('duplicate_requests', $optimizations);
        $this->assertArrayHasKey('cache_opportunities', $optimizations);
        $this->assertArrayHasKey('batch_opportunities', $optimizations);
        $this->assertArrayHasKey('potential_savings', $optimizations);
    }

    public function test_can_track_cost_by_feature()
    {
        $this->createTestRequestsWithFeatures();

        $costByFeature = $this->costService->trackCostByFeature();

        $this->assertIsArray($costByFeature);
        $this->assertNotEmpty($costByFeature);

        foreach ($costByFeature as $feature => $cost) {
            $this->assertIsString($feature);
            $this->assertIsNumeric($cost);
        }
    }

    public function test_can_set_budget_limits()
    {
        $newLimits = [
            'daily' => 150.0,
            'weekly' => 750.0,
            'monthly' => 2500.0,
        ];

        $result = $this->costService->setBudgetLimits($newLimits);

        $this->assertTrue($result['success']);

        $updatedLimits = $this->costService->getBudgetLimits();
        $this->assertEquals($newLimits['daily'], $updatedLimits['daily']);
        $this->assertEquals($newLimits['weekly'], $updatedLimits['weekly']);
        $this->assertEquals($newLimits['monthly'], $updatedLimits['monthly']);
    }

    public function test_can_forecast_costs()
    {
        $this->createTestRequests();

        $forecast = $this->costService->forecastCosts('30d');

        $this->assertIsArray($forecast);
        $this->assertArrayHasKey('predicted_cost', $forecast);
        $this->assertArrayHasKey('confidence_interval', $forecast);
        $this->assertArrayHasKey('trend_factors', $forecast);
        $this->assertArrayHasKey('risk_assessment', $forecast);
    }

    public function test_handles_disabled_optimization()
    {
        config(['genai.cost_optimization.enabled' => false]);

        // 依存関係をモック
        $mockReplacementService = m::mock(ModelReplacementService::class);
        $mockNotificationService = m::mock(NotificationService::class);

        $disabledService = new CostOptimizationService(
            $mockReplacementService,
            $mockNotificationService
        );

        $result = $disabledService->analyzeCostTrends('7d');

        $this->assertIsArray($result);
        $this->assertEquals('disabled', $result['status']);
    }

    public function test_validates_budget_limits()
    {
        $invalidLimits = [
            'daily' => -100,
            'weekly' => 'invalid',
            'monthly' => null,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->costService->setBudgetLimits($invalidLimits);
    }

    private function createTestRequests(): void
    {
        $providers = ['openai', 'claude', 'gemini'];
        $models = [
            'openai' => ['gpt-4', 'gpt-4o-mini'],
            'claude' => ['claude-3-opus', 'claude-3-sonnet'],
            'gemini' => ['gemini-1.5-pro', 'gemini-1.5-flash'],
        ];

        for ($i = 0; $i < 50; $i++) {
            $provider = $providers[array_rand($providers)];
            $model = $models[$provider][array_rand($models[$provider])];

            GenAIRequest::create([
                'provider' => $provider,
                'model' => $model,
                'prompt' => "Test prompt {$i}",
                'response' => "Test response {$i}",
                'input_tokens' => rand(50, 200),
                'output_tokens' => rand(20, 100),
                'total_tokens' => rand(70, 300),
                'cost' => rand(1, 10) / 100, // 0.01 to 0.10
                'response_time_ms' => rand(1000, 3000),
                'is_cached' => rand(0, 1),
                'created_at' => now()->subDays(rand(0, 7)),
            ]);
        }
    }

    private function createHighCostRequests(): void
    {
        for ($i = 0; $i < 10; $i++) {
            GenAIRequest::create([
                'provider' => 'openai',
                'model' => 'gpt-4',
                'prompt' => "Expensive prompt {$i}",
                'response' => "Expensive response {$i}",
                'input_tokens' => rand(500, 1000),
                'output_tokens' => rand(200, 500),
                'total_tokens' => rand(700, 1500),
                'cost' => rand(10, 20) / 100, // 0.10 to 0.20 (高コスト)
                'response_time_ms' => rand(2000, 5000),
                'is_cached' => false,
                'created_at' => now(),
            ]);
        }
    }

    private function createDuplicateRequests(): void
    {
        $duplicatePrompt = 'What is artificial intelligence?';

        for ($i = 0; $i < 5; $i++) {
            GenAIRequest::create([
                'provider' => 'openai',
                'model' => 'gpt-4',
                'prompt' => $duplicatePrompt,
                'response' => 'AI is a field of computer science...',
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
                'cost' => 0.05,
                'response_time_ms' => 1500,
                'is_cached' => false,
                'created_at' => now()->subMinutes($i * 10),
            ]);
        }
    }

    private function createTestRequestsWithFeatures(): void
    {
        $features = ['chat', 'summarization', 'translation', 'code_generation'];

        foreach ($features as $feature) {
            for ($i = 0; $i < 10; $i++) {
                GenAIRequest::create([
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                    'prompt' => "Feature: {$feature} - Test prompt {$i}",
                    'response' => "Feature response {$i}",
                    'input_tokens' => rand(50, 200),
                    'output_tokens' => rand(20, 100),
                    'total_tokens' => rand(70, 300),
                    'cost' => rand(1, 5) / 100,
                    'response_time_ms' => rand(1000, 3000),
                    'is_cached' => false,
                    'created_at' => now()->subDays(rand(0, 3)),
                    'metadata' => json_encode(['feature' => $feature]),
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
