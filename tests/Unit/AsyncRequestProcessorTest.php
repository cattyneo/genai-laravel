<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Services\GenAI\AsyncRequestProcessor;
use CattyNeo\LaravelGenAI\Services\GenAI\CostCalculator;
use CattyNeo\LaravelGenAI\Services\GenAI\ProviderFactory;
use CattyNeo\LaravelGenAI\Services\GenAI\ResolvedRequestConfig;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class AsyncRequestProcessorTest extends TestCase
{
    private AsyncRequestProcessor $processor;

    private ProviderFactory $providerFactory;

    private CostCalculator $costCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        // 両方のクラスは実際のインスタンスを使用（finalクラスのため）
        $this->providerFactory = new ProviderFactory(config('genai.providers', []));

        // テスト用のモデル定義
        $testModels = [
            'gpt-4.1-mini' => [
                'provider' => 'openai',
                'pricing' => ['input' => 0.40, 'output' => 1.60],
            ],
            'claude-sonnet-4' => [
                'provider' => 'claude',
                'pricing' => ['input' => 3.00, 'output' => 15.00],
            ],
            'gemini-2.5-flash' => [
                'provider' => 'gemini',
                'pricing' => ['input' => 0.10, 'output' => 0.40],
            ],
        ];

        $this->costCalculator = new CostCalculator(
            $testModels,
            config('genai.pricing', [])
        );

        $this->processor = new AsyncRequestProcessor(
            $this->providerFactory,
            $this->costCalculator
        );
    }

    public function test_prepare_request_data_for_openai(): void
    {
        $config = new ResolvedRequestConfig(
            provider: 'openai',
            model: 'gpt-4.1-mini',
            prompt: 'Hello, world!',
            systemPrompt: 'You are a helpful assistant.',
            options: ['temperature' => 0.7, 'max_tokens' => 100],
            vars: [],
            stream: false
        );

        $providerConfig = [
            'api_key' => 'test-key',
            'base_url' => 'https://api.openai.com/v1',
        ];

        // 実際のプロバイダーを使用

        $result = $this->processor->prepareRequestData($config, $providerConfig);

        $this->assertEquals('https://api.openai.com/v1/chat/completions', $result['url']);
        $this->assertEquals('Bearer test-key', $result['headers']['Authorization']);
        $this->assertEquals('application/json', $result['headers']['Content-Type']);
        $this->assertEquals('gpt-4.1-mini', $result['payload']['model']);
        $this->assertCount(2, $result['payload']['messages']);
        $this->assertEquals('system', $result['payload']['messages'][0]['role']);
        $this->assertEquals('You are a helpful assistant.', $result['payload']['messages'][0]['content']);
        $this->assertEquals('user', $result['payload']['messages'][1]['role']);
        $this->assertEquals('Hello, world!', $result['payload']['messages'][1]['content']);
    }

    public function test_prepare_request_data_for_claude(): void
    {
        $config = new ResolvedRequestConfig(
            provider: 'claude',
            model: 'claude-sonnet-4',
            prompt: 'Hello, world!',
            systemPrompt: 'You are a helpful assistant.',
            options: ['temperature' => 0.7, 'max_tokens' => 100],
            vars: [],
            stream: false
        );

        $providerConfig = [
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com/v1',
        ];

        // 実際のプロバイダーを使用

        $result = $this->processor->prepareRequestData($config, $providerConfig);

        $this->assertEquals('https://api.anthropic.com/v1/messages', $result['url']);
        $this->assertEquals('test-key', $result['headers']['x-api-key']);
        $this->assertEquals('application/json', $result['headers']['Content-Type']);
        $this->assertEquals('2023-06-01', $result['headers']['anthropic-version']);
        $this->assertEquals('claude-sonnet-4', $result['payload']['model']);
        $this->assertEquals('You are a helpful assistant.', $result['payload']['system']);
        $this->assertCount(1, $result['payload']['messages']);
        $this->assertEquals('user', $result['payload']['messages'][0]['role']);
        $this->assertEquals('Hello, world!', $result['payload']['messages'][0]['content']);
    }

    public function test_create_response_with_openai_format(): void
    {
        $rawResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Hello! How can I help you today?',
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 8,
                'total_tokens' => 18,
            ],
        ];

        // 実際のコスト計算を使用

        $startTime = microtime(true);
        $response = $this->processor->createResponse($rawResponse, 'gpt-4.1-mini', $startTime);

        $this->assertEquals('Hello! How can I help you today?', $response->content);
        $this->assertEquals($rawResponse['usage'], $response->usage);
        $this->assertGreaterThanOrEqual(0.0, $response->cost); // コスト計算は実際の設定に依存
        $this->assertEquals($rawResponse, $response->meta);
        $this->assertFalse($response->cached);
        $this->assertGreaterThanOrEqual(0, $response->responseTimeMs);
    }

    public function test_create_response_with_gemini_format(): void
    {
        $rawResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello! How can I help you today?'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 8,
                'totalTokenCount' => 18,
            ],
        ];

        // 実際のコスト計算を使用

        $startTime = microtime(true);
        $response = $this->processor->createResponse($rawResponse, 'gemini-2.5-flash', $startTime);

        $this->assertEquals('Hello! How can I help you today?', $response->content);
        $this->assertEquals($rawResponse['usageMetadata'], $response->usage);
        $this->assertGreaterThanOrEqual(0.0, $response->cost); // コスト計算は実際の設定に依存
        $this->assertEquals($rawResponse, $response->meta);
        $this->assertFalse($response->cached);
        $this->assertGreaterThanOrEqual(0, $response->responseTimeMs);
    }

    public function test_process_multiple_async_requests(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'OpenAI response']]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => 'Claude response']],
                'usage' => ['input_tokens' => 8, 'output_tokens' => 6],
            ]),
        ]);

        $configs = [
            new ResolvedRequestConfig(
                provider: 'openai',
                model: 'gpt-4.1-mini',
                prompt: 'Hello OpenAI',
                systemPrompt: null,
                options: [],
                vars: [],
                stream: false
            ),
            new ResolvedRequestConfig(
                provider: 'claude',
                model: 'claude-sonnet-4',
                prompt: 'Hello Claude',
                systemPrompt: null,
                options: [],
                vars: [],
                stream: false
            ),
        ];

        // 実際のプロバイダーファクトリーを使用

        // 実際のコスト計算を使用

        $startTime = microtime(true);
        $responses = $this->processor->processMultipleAsync($configs, $startTime);

        $this->assertCount(2, $responses);
        $this->assertEquals('OpenAI response', $responses[0]->content);
        $this->assertEquals('Claude response', $responses[1]->content);
    }

    public function test_unsupported_provider_throws_exception(): void
    {
        $config = new ResolvedRequestConfig(
            provider: 'unsupported',
            model: 'test-model',
            prompt: 'Hello',
            systemPrompt: null,
            options: [],
            vars: [],
            stream: false
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider: unsupported');

        $this->processor->prepareRequestData($config, []);
    }
}
