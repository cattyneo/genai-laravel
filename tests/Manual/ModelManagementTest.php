<?php

namespace CattyNeo\LaravelGenAI\Tests\Manual;

use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\ClaudeFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GeminiFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GrokFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

/**
 * モデル管理機能の手動テスト
 *
 * ⚠️ 実際のAPI接続を行うため手動実行のみ
 * APIキーが設定されている場合のみ実行されます
 */
class ModelManagementTest extends TestCase
{
    private ModelRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(ModelRepository::class);
    }

    /**
     * ⚠️ 手動実行のみ: OpenAI APIからモデル一覧を取得
     */
    public function test_fetch_openai_models_from_api()
    {
        if (! config('genai.providers.openai.api_key')) {
            $this->markTestSkipped('OpenAI API key not configured');
        }

        $fetcher = app(OpenAIFetcher::class);

        if (! $fetcher->isAvailable()) {
            $this->markTestSkipped('OpenAI fetcher not available');
        }

        $models = $fetcher->fetchModels();

        $this->assertGreaterThan(0, $models->count());

        echo "\n=== OpenAI Models ===\n";
        $models->take(5)->each(function ($model) {
            echo "• {$model->id} ({$model->type})\n";
        });
        echo "Total: {$models->count()} models\n\n";
    }

    /**
     * ⚠️ 手動実行のみ: Gemini APIからモデル一覧を取得
     */
    public function test_fetch_gemini_models_from_api()
    {
        if (! config('genai.providers.gemini.api_key')) {
            $this->markTestSkipped('Gemini API key not configured');
        }

        $fetcher = app(GeminiFetcher::class);

        if (! $fetcher->isAvailable()) {
            $this->markTestSkipped('Gemini fetcher not available');
        }

        $models = $fetcher->fetchModels();

        $this->assertGreaterThan(0, $models->count());

        echo "\n=== Gemini Models ===\n";
        $models->each(function ($model) {
            echo "• {$model->id} ({$model->type}) - {$model->description}\n";
        });
        echo "Total: {$models->count()} models\n\n";
    }

    /**
     * ⚠️ 手動実行のみ: Claude APIからモデル一覧を取得
     */
    public function test_fetch_claude_models_from_api()
    {
        if (! config('genai.providers.claude.api_key')) {
            $this->markTestSkipped('Claude API key not configured');
        }

        $fetcher = app(ClaudeFetcher::class);

        if (! $fetcher->isAvailable()) {
            $this->markTestSkipped('Claude fetcher not available');
        }

        $models = $fetcher->fetchModels();

        $this->assertGreaterThan(0, $models->count());

        echo "\n=== Claude Models ===\n";
        $models->each(function ($model) {
            echo "• {$model->id} ({$model->type})\n";
        });
        echo "Total: {$models->count()} models\n\n";
    }

    /**
     * ⚠️ 手動実行のみ: Grok APIからモデル一覧を取得
     */
    public function test_fetch_grok_models_from_api()
    {
        if (! config('genai.providers.grok.api_key')) {
            $this->markTestSkipped('Grok API key not configured');
        }

        $fetcher = app(GrokFetcher::class);

        if (! $fetcher->isAvailable()) {
            $this->markTestSkipped('Grok fetcher not available');
        }

        $models = $fetcher->fetchModels();

        $this->assertGreaterThan(0, $models->count());

        echo "\n=== Grok Models ===\n";
        $models->each(function ($model) {
            echo "• {$model->id} ({$model->type})\n";
        });
        echo "Total: {$models->count()} models\n\n";
    }

    /**
     * モデルリポジトリの統合テスト
     */
    public function test_model_repository_integration()
    {
        // YAMLファイルが存在するかチェック
        $yamlPath = storage_path('genai/models.yaml');

        if (! File::exists($yamlPath)) {
            $this->markTestSkipped('Models YAML file not found');
        }

        // 全モデルを取得
        $models = $this->repository->getAllModels();

        echo "\n=== Models from YAML ===\n";
        echo "Total: {$models->count()} models\n";

        $grouped = $models->groupBy('provider');
        foreach ($grouped as $provider => $providerModels) {
            echo "\n{$provider}: {$providerModels->count()} models\n";
            $providerModels->take(3)->each(function ($model) {
                echo "  • {$model->id} ({$model->type})\n";
            });
        }

        $this->assertGreaterThan(0, $models->count());
    }

    /**
     * YAML検証テスト
     */
    public function test_yaml_validation()
    {
        $validation = $this->repository->validateYaml();

        echo "\n=== YAML Validation ===\n";
        echo 'Valid: '.($validation['valid'] ? 'YES' : 'NO')."\n";

        if (! $validation['valid']) {
            echo "Errors:\n";
            foreach ($validation['errors'] as $error) {
                echo "  • {$error}\n";
            }
        }

        echo "\n";
    }

    /**
     * プロバイダー別モデル取得テスト
     */
    public function test_get_models_by_provider()
    {
        $providers = ['openai', 'gemini', 'claude', 'grok'];

        echo "\n=== Models by Provider ===\n";

        foreach ($providers as $provider) {
            $models = $this->repository->getModelsByProvider($provider);
            echo "{$provider}: {$models->count()} models\n";

            if ($models->count() > 0) {
                $models->take(2)->each(function ($model) {
                    echo "  • {$model->id}\n";
                });
            }
        }
        echo "\n";
    }

    /**
     * 特定モデルの詳細取得テスト
     */
    public function test_get_specific_model_details()
    {
        $testModels = ['gpt-4.1', 'claude-sonnet-4', 'gemini-2.5-pro', 'grok-3'];

        echo "\n=== Model Details ===\n";

        foreach ($testModels as $modelId) {
            $model = $this->repository->getModel($modelId);

            if ($model) {
                echo "✅ {$modelId}:\n";
                echo "   Provider: {$model->provider}\n";
                echo "   Type: {$model->type}\n";
                echo '   Features: '.implode(', ', $model->features)."\n";
                if ($model->maxTokens) {
                    echo '   Max Tokens: '.number_format($model->maxTokens)."\n";
                }
                if ($model->contextWindow) {
                    echo '   Context Window: '.number_format($model->contextWindow)."\n";
                }
                echo "\n";
            } else {
                echo "❌ {$modelId}: Not found\n\n";
            }
        }
    }

    /**
     * キャッシュ動作テスト
     */
    public function test_cache_behavior()
    {
        echo "\n=== Cache Test ===\n";

        // 初回読み込み
        $start1 = microtime(true);
        $models1 = $this->repository->getAllModels();
        $time1 = round((microtime(true) - $start1) * 1000, 2);

        // キャッシュからの読み込み
        $start2 = microtime(true);
        $models2 = $this->repository->getAllModels();
        $time2 = round((microtime(true) - $start2) * 1000, 2);

        echo "First load: {$time1}ms ({$models1->count()} models)\n";
        echo "Cached load: {$time2}ms ({$models2->count()} models)\n";
        echo 'Speed improvement: '.round($time1 / $time2, 1)."x\n\n";

        $this->assertEquals($models1->count(), $models2->count());
        $this->assertLessThan($time1, $time2);
    }

    protected function getPackageProviders($app)
    {
        return [
            \CattyNeo\LaravelGenAI\GenAIServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // テスト用の設定
        $app['config']->set('genai.cache.enabled', true);
        $app['config']->set('genai.cache.driver', 'array');
    }
}

/**
 * 手動テスト実行スクリプト
 *
 * 実行方法:
 * ```bash
 * # 全テスト
 * php artisan test tests/Manual/ModelManagementTest.php
 *
 * # 特定メソッド
 * php artisan test --filter=test_fetch_openai_models_from_api
 * ```
 *
 * 注意事項:
 * - 実際のAPI接続を行うため、APIキーが必要
 * - レート制限に注意
 * - 本番環境では実行しない
 */
