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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
        $testStoragePath = __DIR__.'/../storage/genai';
        config()->set('genai.paths.models', $testStoragePath.'/models.yaml');
        config()->set('genai.paths.presets', $testStoragePath.'/presets');
        config()->set('genai.paths.prompts', $testStoragePath.'/prompts');

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
}
