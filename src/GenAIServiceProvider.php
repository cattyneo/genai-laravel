<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI;

use CattyNeo\LaravelGenAI\Actions\RequestAction;
use CattyNeo\LaravelGenAI\Commands\GenAIAnalyticsCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIAssistantImportCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIInstallCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIModelAddCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIModelListCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIModelUpdateCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIModelValidateCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIPresetGenerateCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIScheduledUpdateCommand;
use CattyNeo\LaravelGenAI\Commands\GenAIStatsCommand;
use CattyNeo\LaravelGenAI\Commands\GenAITestCommand;
use CattyNeo\LaravelGenAI\Services\GenAI\AssistantImportService;
use CattyNeo\LaravelGenAI\Services\GenAI\AsyncRequestProcessor;
use CattyNeo\LaravelGenAI\Services\GenAI\CacheManager;
use CattyNeo\LaravelGenAI\Services\GenAI\CostCalculator;
use CattyNeo\LaravelGenAI\Services\GenAI\CostOptimizationService;
use CattyNeo\LaravelGenAI\Services\GenAI\DatabaseLogger;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\ClaudeFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GeminiFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GrokFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIAssistantFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIFetcher;
use CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager;
use CattyNeo\LaravelGenAI\Services\GenAI\LoggerAdapter;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Services\GenAI\PerformanceMonitoringService;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetAutoUpdateService;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetRepository;
use CattyNeo\LaravelGenAI\Services\GenAI\PromptManager;
use CattyNeo\LaravelGenAI\Services\GenAI\ProviderFactory;
use CattyNeo\LaravelGenAI\Services\GenAI\RateLimiter;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestConfiguration;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestLogger;
use CattyNeo\LaravelGenAI\Services\GenAI\RequestProcessor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class GenAIServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        // 設定ファイルのpublish
        $this->publishes([
            __DIR__ . '/../config/genai.php' => config_path('genai.php'),
        ], 'config');

        // マイグレーションファイルのpublish
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        // プリセット設定ファイルのpublish
        $this->publishes([
            __DIR__ . '/../storage/genai/presets/' => storage_path('genai/presets'),
        ], 'presets');

        // プロンプトファイルのpublish
        $this->publishes([
            __DIR__ . '/../storage/genai/prompts/' => storage_path('genai/prompts'),
        ], 'prompts');

        // ルートファイルの読み込み
        $this->loadRoutesFrom(__DIR__ . '/../routes/genai-api.php');

        // Artisan コマンドの登録
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenAIInstallCommand::class,
                GenAITestCommand::class,
                GenAIStatsCommand::class,
                GenAIModelListCommand::class,
                GenAIModelAddCommand::class,
                GenAIModelValidateCommand::class,
                GenAIPresetGenerateCommand::class,
                GenAIModelUpdateCommand::class,
                GenAIScheduledUpdateCommand::class,
                GenAIAnalyticsCommand::class,
                GenAIAssistantImportCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // 設定ファイルのマージ
        $this->mergeConfigFrom(__DIR__ . '/../config/genai.php', 'genai');

        // Core services registration
        $this->registerCoreServices();

        // Model management services registration
        $this->registerModelServices();

        // New service classes registration
        $this->registerRequestServices();

        // Main services registration
        $this->registerMainServices();

        // Facadeのaliasを登録
        $this->app->alias(GenAIManager::class, 'genai');
    }

    /**
     * モデル管理サービスの登録
     */
    private function registerModelServices(): void
    {
        // Model Repository
        $this->app->bind(ModelRepository::class, function ($app) {
            return new ModelRepository(
                config('genai.paths.models', storage_path('genai/models.yaml')),
                config('genai.cache.ttl', 3600)
            );
        });

        // Fetcher services
        $this->app->bind(OpenAIFetcher::class, function ($app) {
            $config = config('genai.providers.openai', []);

            return new OpenAIFetcher($app->make(HttpFactory::class), $config);
        });

        $this->app->bind(GeminiFetcher::class, function ($app) {
            $config = config('genai.providers.gemini', []);

            return new GeminiFetcher($app->make(HttpFactory::class), $config);
        });

        $this->app->bind(ClaudeFetcher::class, function ($app) {
            $config = config('genai.providers.claude', []);

            return new ClaudeFetcher($app->make(HttpFactory::class), $config);
        });

        $this->app->bind(GrokFetcher::class, function ($app) {
            $config = config('genai.providers.grok', []);

            return new GrokFetcher($app->make(HttpFactory::class), $config);
        });

        // OpenAI Assistant Fetcher
        $this->app->bind(OpenAIAssistantFetcher::class, function ($app) {
            $config = config('genai.providers.openai', []);

            return new OpenAIAssistantFetcher($config);
        });

        // Assistant Import Service
        $this->app->singleton(AssistantImportService::class, function ($app) {
            return new AssistantImportService(
                $app->make(OpenAIAssistantFetcher::class)
            );
        });
    }

    /**
     * コアサービスの登録
     */
    private function registerCoreServices(): void
    {
        $this->app->singleton(ProviderFactory::class, function ($app) {
            return new ProviderFactory(config('genai.providers', []));
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(config('genai.cache', []));
        });

        // 最適化されたDatabaseLoggerを使用
        $this->app->singleton(DatabaseLogger::class, function ($app) {
            $config = config('genai.logging', []);

            return new DatabaseLogger(
                enabled: $config['enabled'] ?? true,
                batchSize: $config['batch_size'] ?? 10,
                deferStatsUpdate: $config['defer_stats_update'] ?? true
            );
        });

        // 既存のRequestLoggerも互換性のために残す
        $this->app->singleton(RequestLogger::class, function ($app) {
            return new RequestLogger(config('genai.logging.enabled', true));
        });

        // LoggerAdapterで統一インターフェースを提供
        $this->app->singleton(LoggerAdapter::class, function ($app) {
            $useOptimizedLogger = config('genai.logging.use_optimized', true);

            if ($useOptimizedLogger) {
                return new LoggerAdapter($app->make(DatabaseLogger::class));
            } else {
                return new LoggerAdapter($app->make(RequestLogger::class));
            }
        });

        $this->app->singleton(CostCalculator::class, function ($app) {
            $modelRepository = $app->make(ModelRepository::class);
            $models = [];

            try {
                $modelInfos = $modelRepository->getAllModels();
                foreach ($modelInfos as $modelInfo) {
                    $models[$modelInfo->id] = [
                        'provider' => $modelInfo->provider,
                        'model' => $modelInfo->id,
                        'type' => $modelInfo->type,
                        'features' => $modelInfo->features,
                        'pricing' => $modelInfo->pricing,
                        'limits' => $modelInfo->limits,
                    ];
                }
            } catch (\Exception $e) {
                // YAML読み込みに失敗した場合はログ出力して空配列で続行
                logger()->warning('Failed to load models from YAML: ' . $e->getMessage());
            }

            return new CostCalculator(
                $models,
                config('genai.pricing', [])
            );
        });

        $this->app->singleton(PresetRepository::class, function ($app) {
            return new PresetRepository(config('genai.paths.presets', 'genai/presets'));
        });

        $this->app->singleton(PromptManager::class, function ($app) {
            return new PromptManager(
                config('genai.paths.prompts', 'storage/genai/prompts')
            );
        });
    }

    /**
     * リクエスト処理サービスの登録
     */
    private function registerRequestServices(): void
    {
        $this->app->singleton(RequestConfiguration::class, function ($app) {
            return new RequestConfiguration(
                $app->make(PresetRepository::class),
                config('genai.defaults', [])
            );
        });

        $this->app->singleton(RequestProcessor::class, function ($app) {
            return new RequestProcessor(
                $app->make(ProviderFactory::class),
                $app->make(CostCalculator::class),
                $app->make(RateLimiter::class)
            );
        });

        $this->app->singleton(AsyncRequestProcessor::class, function ($app) {
            return new AsyncRequestProcessor(
                $app->make(ProviderFactory::class),
                $app->make(CostCalculator::class)
            );
        });

        $this->app->singleton(RateLimiter::class, function ($app) {
            return new RateLimiter(
                config('genai.rate_limits', []),
                config('genai.cache.driver', 'redis')
            );
        });
    }

    /**
     * メインサービスの登録
     */
    private function registerMainServices(): void
    {
        $this->app->singleton(RequestAction::class, function ($app) {
            return new RequestAction(
                $app->make(RequestConfiguration::class),
                $app->make(RequestProcessor::class),
                $app->make(AsyncRequestProcessor::class),
                $app->make(CacheManager::class),
                $app->make(LoggerAdapter::class)
            );
        });

        $this->app->singleton(GenAIManager::class, function ($app) {
            return new GenAIManager(
                $app->make(RequestAction::class),
                $app->make(PromptManager::class),
                config('genai.providers', [])
            );
        });

        // 新しいサービスの登録
        $this->app->singleton(ModelReplacementService::class, function ($app) {
            return new ModelReplacementService(
                $app->make(ModelRepository::class)
            );
        });

        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService(
                config('genai.notifications', [])
            );
        });

        $this->app->singleton(PresetAutoUpdateService::class, function ($app) {
            return new PresetAutoUpdateService(
                $app->make(ModelReplacementService::class),
                $app->make(PresetRepository::class),
                $app->make(NotificationService::class)
            );
        });

        $this->app->singleton(PerformanceMonitoringService::class, function ($app) {
            return new PerformanceMonitoringService(
                $app->make(NotificationService::class)
            );
        });

        $this->app->singleton(CostOptimizationService::class, function ($app) {
            return new CostOptimizationService(
                $app->make(ModelReplacementService::class),
                $app->make(NotificationService::class)
            );
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            GenAIManager::class,
            PromptManager::class,
            ProviderFactory::class,
            CacheManager::class,
            RequestLogger::class,
            CostCalculator::class,
            PresetRepository::class,
            RequestConfiguration::class,
            RequestProcessor::class,
            AsyncRequestProcessor::class,
            RateLimiter::class,
            DatabaseLogger::class,
            LoggerAdapter::class,
            RequestAction::class,
            ModelRepository::class,
            OpenAIFetcher::class,
            GeminiFetcher::class,
            ClaudeFetcher::class,
            GrokFetcher::class,
            ModelReplacementService::class,
            NotificationService::class,
            PresetAutoUpdateService::class,
            PerformanceMonitoringService::class,
            CostOptimizationService::class,
            AssistantImportService::class,
            OpenAIAssistantFetcher::class,
            'genai',
        ];
    }
}
