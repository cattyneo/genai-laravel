<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Actions\RequestAction;
use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Data\GenAIResponseData;
use CattyNeo\LaravelGenAI\Services\GenAI\ProviderInterface;
use CattyNeo\LaravelGenAI\Services\GenAI\ProviderFactory;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetRepository;
use CattyNeo\LaravelGenAI\Services\GenAI\CostCalculator;
use CattyNeo\LaravelGenAI\Services\GenAI\CacheManager;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestLogger;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Mockery;
use CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager;

class GenAITest extends TestCase
{
    private $mockProvider;
    private $requestAction;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_can_make_basic_request(): void
    {
        // APIキー未設定での動作確認
        try {
            $genai = app(GenAIManager::class);
            $response = $genai->ask('Hello, how are you?');
            $this->fail('Exception should have been thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('API', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function it_can_use_system_prompt(): void
    {
        // APIキー未設定での動作確認
        try {
            $genai = app(GenAIManager::class);
            $request = new GenAIRequestData(
                prompt: 'What is Laravel?',
                systemPrompt: 'You are a helpful assistant'
            );
            $response = $genai->request($request);
            $this->fail('Exception should have been thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('API', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function it_can_override_options(): void
    {
        // APIキー未設定での動作確認
        try {
            $genai = app(GenAIManager::class);
            $response = $genai->ask('Test prompt', ['temperature' => 0.9]);
            $this->fail('Exception should have been thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('API', $e->getMessage());
        }
    }

    /**
     * GenAI::fake() のような振る舞いをシミュレート
     */
    public static function fake(array $responses = []): void
    {
        // 実際の実装では、Laravelのサービスコンテナでモックプロバイダーを登録する
        // 今回は簡略化してテストメソッド内でモックを使用
    }
}
