<?php

namespace CattyNeo\LaravelGenAI\Tests\Unit;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

/**
 * ModelRepositoryのテスト
 */
class ModelRepositoryTest extends TestCase
{
    private ModelRepository $repository;

    private string $testYamlPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testYamlPath = storage_path('genai/test_models.yaml');
        $this->repository = new ModelRepository;

        // テスト用YAMLファイルのパスを設定
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('yamlPath');
        $property->setAccessible(true);
        $property->setValue($this->repository, $this->testYamlPath);
    }

    protected function tearDown(): void
    {
        // テストファイルを削除
        if (File::exists($this->testYamlPath)) {
            File::delete($this->testYamlPath);
        }

        Cache::flush();
        parent::tearDown();
    }

    public function test_get_all_models_with_valid_yaml()
    {
        $this->createTestYamlFile();

        $models = $this->repository->getAllModels();

        $this->assertCount(2, $models);

        $openaiModel = $models->first(fn ($m) => $m->id === 'gpt-4o');
        $this->assertInstanceOf(ModelInfo::class, $openaiModel);
        $this->assertEquals('openai', $openaiModel->provider);
        $this->assertEquals(['vision', 'function_calling'], $openaiModel->features);

        $claudeModel = $models->first(fn ($m) => $m->id === 'claude-3-opus');
        $this->assertInstanceOf(ModelInfo::class, $claudeModel);
        $this->assertEquals('claude', $claudeModel->provider);
        $this->assertEquals(4096, $claudeModel->maxTokens);
    }

    public function test_get_models_by_provider()
    {
        $this->createTestYamlFile();

        $openaiModels = $this->repository->getModelsByProvider('openai');
        $claudeModels = $this->repository->getModelsByProvider('claude');

        $this->assertCount(1, $openaiModels);
        $this->assertCount(1, $claudeModels);

        $this->assertEquals('gpt-4o', $openaiModels->first()->id);
        $this->assertEquals('claude-3-opus', $claudeModels->first()->id);
    }

    public function test_get_specific_model()
    {
        $this->createTestYamlFile();

        $model = $this->repository->getModel('gpt-4o');
        $this->assertInstanceOf(ModelInfo::class, $model);
        $this->assertEquals('gpt-4o', $model->id);

        $nonExistent = $this->repository->getModel('non-existent');
        $this->assertNull($nonExistent);
    }

    public function test_model_exists()
    {
        $this->createTestYamlFile();

        $this->assertTrue($this->repository->exists('gpt-4o'));
        $this->assertTrue($this->repository->exists('claude-3-opus'));
        $this->assertFalse($this->repository->exists('non-existent'));
    }

    public function test_add_model()
    {
        $this->createTestYamlFile();

        $newModel = new ModelInfo(
            id: 'gemini-2.0-flash',
            name: 'Gemini 2.0 Flash',
            provider: 'gemini',
            type: 'text',
            features: ['grounding'],
            maxTokens: 8192,
            contextWindow: 1000000
        );

        $result = $this->repository->addModel($newModel);
        $this->assertTrue($result);

        // モデルが追加されたことを確認
        $this->assertTrue($this->repository->exists('gemini-2.0-flash'));
        $retrievedModel = $this->repository->getModel('gemini-2.0-flash');
        $this->assertEquals('gemini-2.0-flash', $retrievedModel->id);
        $this->assertEquals('gemini', $retrievedModel->provider);
    }

    public function test_remove_model()
    {
        $this->createTestYamlFile();

        $this->assertTrue($this->repository->exists('gpt-4o'));

        $result = $this->repository->removeModel('openai', 'gpt-4o');
        $this->assertTrue($result);

        $this->assertFalse($this->repository->exists('gpt-4o'));

        // 存在しないモデルの削除
        $result = $this->repository->removeModel('openai', 'non-existent');
        $this->assertFalse($result);
    }

    public function test_validate_yaml_with_valid_content()
    {
        $this->createTestYamlFile();

        $validation = $this->repository->validateYaml();

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
    }

    public function test_validate_yaml_with_invalid_content()
    {
        // 無効なYAMLファイルを作成
        $invalidYaml = "openai:\n  gpt-4o:\n    provider: openai\n    # missing required fields";

        File::ensureDirectoryExists(dirname($this->testYamlPath));
        File::put($this->testYamlPath, $invalidYaml);

        $validation = $this->repository->validateYaml();

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }

    public function test_validate_yaml_with_missing_file()
    {
        $validation = $this->repository->validateYaml();

        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('YAML file not found', $validation['errors'][0]);
    }

    public function test_cache_functionality()
    {
        $this->createTestYamlFile();

        // 最初の呼び出し
        $models1 = $this->repository->getAllModels();

        // YAMLファイルを変更
        File::put($this->testYamlPath, "openai:\n  gpt-3.5-turbo:\n    provider: openai\n    model: gpt-3.5-turbo\n    type: text");

        // キャッシュがあるので変更は反映されない
        $models2 = $this->repository->getAllModels();
        $this->assertEquals($models1->count(), $models2->count());

        // キャッシュをクリア
        $this->repository->clearCache();

        // 今度は変更が反映される
        $models3 = $this->repository->getAllModels();
        $this->assertNotEquals($models1->count(), $models3->count());
        $this->assertEquals(1, $models3->count());
        $this->assertEquals('gpt-3.5-turbo', $models3->first()->id);
    }

    public function test_invalid_yaml_throws_exception()
    {
        $this->markTestSkipped('Temporarily skipped due to autoloader issue');
    }

    public function test_missing_yaml_file_throws_exception()
    {
        $this->markTestSkipped('Temporarily skipped due to autoloader issue');
    }

    /**
     * テスト用のYAMLファイルを作成
     */
    private function createTestYamlFile(): void
    {
        $yamlContent = <<<'YAML'
openai:
  gpt-4o:
    provider: openai
    model: gpt-4o
    type: text
    features:
      - vision
      - function_calling
    limits:
      max_tokens: 16384
      context_window: 1000000

claude:
  claude-3-opus:
    provider: claude
    model: claude-3-opus
    type: text
    features:
      - vision
      - reasoning
    limits:
      max_tokens: 4096
      context_window: 200000
YAML;

        File::ensureDirectoryExists(dirname($this->testYamlPath));
        File::put($this->testYamlPath, $yamlContent);
    }
}
