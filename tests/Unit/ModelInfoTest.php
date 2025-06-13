<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Data\ModelInfo;
use PHPUnit\Framework\TestCase;

/**
 * ModelInfo DTOのテスト
 */
class ModelInfoTest extends TestCase
{
    public function test_modelinfo_creation_with_basic_data()
    {
        $modelInfo = new ModelInfo(
            id: 'gpt-4o',
            name: 'GPT-4 Omni',
            provider: 'openai',
            type: 'text',
            features: ['vision', 'function_calling'],
            maxTokens: 16384,
            contextWindow: 1000000
        );

        $this->assertEquals('gpt-4o', $modelInfo->id);
        $this->assertEquals('GPT-4 Omni', $modelInfo->name);
        $this->assertEquals('openai', $modelInfo->provider);
        $this->assertEquals('text', $modelInfo->type);
        $this->assertEquals(['vision', 'function_calling'], $modelInfo->features);
        $this->assertEquals(16384, $modelInfo->maxTokens);
        $this->assertEquals(1000000, $modelInfo->contextWindow);
    }

    public function test_modelinfo_from_array()
    {
        $data = [
            'id' => 'claude-3-opus',
            'name' => 'Claude 3 Opus',
            'provider' => 'claude',
            'type' => 'text',
            'features' => ['vision', 'reasoning'],
            'max_tokens' => 4096,
            'context_window' => 200000,
            'description' => 'Most capable Claude model',
            'pricing' => ['input' => 15.0, 'output' => 75.0],
            'limits' => ['max_tokens' => 4096, 'context_window' => 200000],
        ];

        $modelInfo = ModelInfo::fromArray($data);

        $this->assertEquals('claude-3-opus', $modelInfo->id);
        $this->assertEquals('Claude 3 Opus', $modelInfo->name);
        $this->assertEquals('claude', $modelInfo->provider);
        $this->assertEquals(['vision', 'reasoning'], $modelInfo->features);
        $this->assertEquals(4096, $modelInfo->maxTokens);
        $this->assertEquals(200000, $modelInfo->contextWindow);
        $this->assertEquals('Most capable Claude model', $modelInfo->description);
        $this->assertEquals(['input' => 15.0, 'output' => 75.0], $modelInfo->pricing);
    }

    public function test_modelinfo_to_array()
    {
        $now = Carbon::now();
        $modelInfo = new ModelInfo(
            id: 'gemini-2.0-flash',
            name: 'Gemini 2.0 Flash',
            provider: 'gemini',
            type: 'text',
            features: ['grounding', 'reasoning'],
            maxTokens: 8192,
            contextWindow: 1000000,
            description: 'Fast Gemini model',
            createdAt: $now,
            pricing: ['input' => 0.10, 'output' => 0.40],
            limits: ['max_tokens' => 8192, 'context_window' => 1000000],
            supportedMethods: ['generateContent'],
            baseModelId: 'gemini-2.0',
            version: '001'
        );

        $array = $modelInfo->toArray();

        $this->assertEquals('gemini-2.0-flash', $array['id']);
        $this->assertEquals('Gemini 2.0 Flash', $array['name']);
        $this->assertEquals('gemini', $array['provider']);
        $this->assertEquals(['grounding', 'reasoning'], $array['features']);
        $this->assertEquals(8192, $array['max_tokens']);
        $this->assertEquals(1000000, $array['context_window']);
        $this->assertEquals('Fast Gemini model', $array['description']);
        $this->assertEquals($now->toISOString(), $array['created_at']);
        $this->assertEquals(['input' => 0.10, 'output' => 0.40], $array['pricing']);
        $this->assertEquals(['generateContent'], $array['supported_methods']);
        $this->assertEquals('gemini-2.0', $array['base_model_id']);
        $this->assertEquals('001', $array['version']);
    }

    public function test_modelinfo_summary()
    {
        $modelInfo = new ModelInfo(
            id: 'gpt-4.1-mini',
            name: 'GPT-4.1 Mini',
            provider: 'openai',
            features: ['vision', 'function_calling', 'structured_output'],
            maxTokens: 16384
        );

        $summary = $modelInfo->getSummary();

        $this->assertStringContainsString('GPT-4.1 Mini', $summary);
        $this->assertStringContainsString('(openai)', $summary);
        $this->assertStringContainsString('(16384 tokens)', $summary);
        $this->assertStringContainsString('[vision, function_calling, structured_output]', $summary);
    }

    public function test_modelinfo_with_default_values()
    {
        $modelInfo = new ModelInfo(
            id: 'test-model',
            name: 'Test Model',
            provider: 'test'
        );

        $this->assertEquals('text', $modelInfo->type);
        $this->assertEquals([], $modelInfo->features);
        $this->assertNull($modelInfo->maxTokens);
        $this->assertNull($modelInfo->contextWindow);
        $this->assertNull($modelInfo->description);
        $this->assertNull($modelInfo->createdAt);
        $this->assertEquals([], $modelInfo->pricing);
        $this->assertEquals([], $modelInfo->limits);
        $this->assertEquals([], $modelInfo->supportedMethods);
        $this->assertNull($modelInfo->baseModelId);
        $this->assertNull($modelInfo->version);
    }

    public function test_modelinfo_from_array_with_alternative_field_names()
    {
        $data = [
            'id' => 'grok-3',
            'provider' => 'grok',
            'maxTokens' => 131072,  // camelCase
            'contextWindow' => 131072,  // camelCase
            'supportedMethods' => ['chat.completions'],  // camelCase
            'baseModelId' => 'grok',  // camelCase
        ];

        $modelInfo = ModelInfo::fromArray($data);

        $this->assertEquals(131072, $modelInfo->maxTokens);
        $this->assertEquals(131072, $modelInfo->contextWindow);
        $this->assertEquals(['chat.completions'], $modelInfo->supportedMethods);
        $this->assertEquals('grok', $modelInfo->baseModelId);
    }
}
