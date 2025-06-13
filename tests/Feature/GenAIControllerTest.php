<?php

declare(strict_types=1);

namespace Tests\Feature;

use CattyNeo\LaravelGenAI\Tests\TestCase;
use Mockery;

class GenAIControllerTest extends TestCase
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

    public function test_genai_endpoint_with_default_prompt(): void
    {
        // テスト用にopenaiプロバイダーがAPI keyなしでエラーになることを確認
        $response = $this->post('/genai');

        // API key未設定時はエラーレスポンスが返る
        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_genai_endpoint_with_custom_prompt(): void
    {
        $response = $this->post('/genai', [
            'prompt' => 'What is Laravel?',
            'systemPrompt' => 'You are a PHP expert',
            'options' => ['temperature' => 0.8],
        ]);

        // API key未設定時はエラーレスポンスが返る
        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_genai_endpoint_handles_exceptions(): void
    {
        $response = $this->post('/genai', [
            'prompt' => 'Test prompt',
        ]);

        // APIキー未設定によるエラー
        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
    }
}
