<?php

declare(strict_types=1);

namespace Tests\Feature;

use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use CattyNeo\LaravelGenAI\Facades\GenAI as GenAIFacade;
use CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Mockery;

class GenAIFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_genai_facade_ask_method(): void
    {
        // APIキー未設定での動作確認
        try {
            GenAIFacade::ask('Hello, how are you?');
            $this->fail('Exception should have been thrown');
        } catch (\Exception $e) {
            // API キー不足か設定エラーが発生することを確認
            $this->assertTrue(
                str_contains($e->getMessage(), 'API') ||
                    str_contains($e->getMessage(), 'key') ||
                    str_contains($e->getMessage(), 'File exists')
            );
        }
    }

    public function test_genai_facade_request_method(): void
    {
        // エラーレスポンスの確認
        try {
            $request = new GenAIRequestData(
                prompt: 'What is Laravel?',
                systemPrompt: 'You are a helpful assistant'
            );
            $response = GenAIFacade::request($request);
            $this->fail('Exception should have been thrown');
        } catch (\Exception $e) {
            // API キー不足か設定エラーが発生することを確認
            $this->assertTrue(
                str_contains($e->getMessage(), 'API') ||
                    str_contains($e->getMessage(), 'key') ||
                    str_contains($e->getMessage(), 'File exists')
            );
        }
    }

    public function test_genai_facade_chaining_methods(): void
    {
        // Act & Assert - presetメソッドが正しく動作することを確認
        $manager = GenAIFacade::preset('default');

        $this->assertInstanceOf(GenAIManager::class, $manager);
    }
}
