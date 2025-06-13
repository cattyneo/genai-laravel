<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Tests;

use CattyNeo\LaravelGenAI\GenAIServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            GenAIServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('genai.cache.enabled', false);
        config()->set('genai.defaults.provider', 'openai');
        config()->set('genai.defaults.model', 'gpt-4o-mini');

        // DatabaseLoggerを無効化してログエラーを回避
        config()->set('genai.logging.enabled', false);

        // テスト環境でのモデルファイルパス設定
        $testStoragePath = storage_path('genai');
        $this->ensureTestDirectoriesExist($testStoragePath);
        config()->set('genai.paths.models', $testStoragePath . '/models.yaml');
        config()->set('genai.paths.presets', $testStoragePath . '/presets');
        config()->set('genai.paths.prompts', $testStoragePath . '/prompts');

        // テスト用API設定
        config()->set('genai.providers.openai.api_key', 'test-api-key');
        config()->set('genai.providers.gemini.api_key', 'test-api-key');
        config()->set('genai.providers.claude.api_key', 'test-api-key');
        config()->set('genai.providers.grok.api_key', 'test-api-key');

        // ログ設定
        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => storage_path('logs/test.log'),
        ]);

        // テスト用ログサービスのバインディング
        $app->singleton('log', function ($app) {
            return new \Illuminate\Log\LogManager($app);
        });

        // テスト用ルートを直接定義
        $app['router']->post('/genai', [\App\Http\Controllers\GenAIController::class, 'test']);
        $app['router']->get('/', function () {
            return view('welcome');
        });
    }

    /**
     * テスト用ディレクトリとファイルを作成
     */
    protected function ensureTestDirectoriesExist(string $basePath): void
    {
        $filesystem = new \Illuminate\Filesystem\Filesystem;

        // ディレクトリを作成
        if (! $filesystem->exists($basePath)) {
            $filesystem->makeDirectory($basePath, 0755, true);
        }

        if (! $filesystem->exists($basePath . '/presets')) {
            $filesystem->makeDirectory($basePath . '/presets', 0755, true);
        }

        if (! $filesystem->exists($basePath . '/prompts')) {
            $filesystem->makeDirectory($basePath . '/prompts', 0755, true);
        }

        // models.yamlファイルを作成
        $modelsYamlPath = $basePath . '/models.yaml';
        if (! $filesystem->exists($modelsYamlPath)) {
            $modelsYaml = <<<'YAML'
openai:
  gpt-4o-mini:
    provider: openai
    model: gpt-4o-mini
    type: text
    features:
      - chat
      - vision
    limits:
      max_tokens: 16384
      context_window: 128000
    pricing:
      input: 0.00015
      output: 0.0006
  gpt-4o:
    provider: openai
    model: gpt-4o
    type: text
    features:
      - chat
      - vision
      - function_calling
    limits:
      max_tokens: 4096
      context_window: 128000
    pricing:
      input: 0.005
      output: 0.015
YAML;
            $filesystem->put($modelsYamlPath, $modelsYaml);
        }
    }
}
