<?php

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * モデル一覧表示コマンド
 */
class GenAIModelListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:model-list
                            {--provider= : 特定のプロバイダーのモデルのみ表示}
                            {--source=yaml : データソース (yaml|api|both)}
                            {--format=table : 出力形式 (table|json)}
                            {--details : 詳細情報を表示}';

    /**
     * The console command description.
     */
    protected $description = '利用可能なGenAIモデルの一覧を表示';

    private ModelRepository $modelRepository;

    public function __construct(ModelRepository $modelRepository)
    {
        parent::__construct();
        $this->modelRepository = $modelRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $provider = $this->option('provider');
        $source = $this->option('source');
        $format = $this->option('format');
        $showDetails = $this->option('details');

        $this->info('🤖 GenAI Models List');
        $this->line('Source: '.strtoupper($source));

        if ($provider) {
            $this->line('Provider: '.strtoupper($provider));
        }

        $this->newLine();

        try {
            $models = collect();

            // データソースに応じてモデルを取得
            if ($source === 'yaml' || $source === 'both') {
                $yamlModels = $provider
                    ? $this->modelRepository->getModelsByProvider($provider)
                    : $this->modelRepository->getAllModels();

                $models = $models->merge($yamlModels);
            }

            if ($source === 'api' || $source === 'both') {
                $apiModels = $this->fetchFromAPI($provider);
                $models = $models->merge($apiModels);
            }

            // 重複を除去（IDベース）
            $models = $models->unique('id');

            if ($models->isEmpty()) {
                $this->warn('モデルが見つかりませんでした。');

                return 0;
            }

            // 出力形式に応じて表示
            if ($format === 'json') {
                $this->outputJson($models);
            } else {
                $this->outputTable($models, $showDetails);
            }

            $this->newLine();
            $this->info("総計: {$models->count()} モデル");
        } catch (\Exception $e) {
            $this->error('エラーが発生しました: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * APIからモデルを取得
     */
    private function fetchFromAPI(?string $provider): \Illuminate\Support\Collection
    {
        $models = collect();
        $providers = $provider ? [$provider] : ['openai', 'gemini', 'claude', 'grok'];

        foreach ($providers as $providerName) {
            try {
                $fetcherClass = $this->getFetcherClass($providerName);
                if (! $fetcherClass) {
                    continue;
                }

                $config = config("genai.providers.{$providerName}", []);
                if (empty($config['api_key'])) {
                    $this->warn("APIキーが設定されていません: {$providerName}");

                    continue;
                }

                $fetcher = App::make($fetcherClass, ['config' => $config]);

                if (! $fetcher->isAvailable()) {
                    $this->warn("プロバイダーが利用できません: {$providerName}");

                    continue;
                }

                $this->line("Fetching from {$providerName} API...");
                $fetchedModels = $fetcher->fetchModels();
                $models = $models->merge($fetchedModels);
            } catch (\Exception $e) {
                $this->warn("APIからの取得に失敗: {$providerName} - ".$e->getMessage());
            }
        }

        return $models;
    }

    /**
     * プロバイダー名からFetcherクラス名を取得
     */
    private function getFetcherClass(string $provider): ?string
    {
        $classMap = [
            'openai' => \CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIFetcher::class,
            'gemini' => \CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GeminiFetcher::class,
            'claude' => \CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\ClaudeFetcher::class,
            'grok' => \CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\GrokFetcher::class,
        ];

        return $classMap[$provider] ?? null;
    }

    /**
     * テーブル形式で出力
     */
    private function outputTable(\Illuminate\Support\Collection $models, bool $showDetails): void
    {
        if ($showDetails) {
            // 詳細情報付きテーブル
            $headers = ['ID', 'Name', 'Provider', 'Type', 'Features', 'Max Tokens', 'Context Window'];
            $rows = $models->map(function ($model) {
                return [
                    $model->id,
                    $model->name,
                    $model->provider,
                    $model->type,
                    implode(', ', array_slice($model->features, 0, 3)).(count($model->features) > 3 ? '...' : ''),
                    $model->maxTokens ? number_format($model->maxTokens) : 'N/A',
                    $model->contextWindow ? number_format($model->contextWindow) : 'N/A',
                ];
            })->toArray();
        } else {
            // 簡潔なテーブル
            $headers = ['ID', 'Provider', 'Type', 'Summary'];
            $rows = $models->map(function ($model) {
                return [
                    $model->id,
                    $model->provider,
                    $model->type,
                    $model->getSummary(),
                ];
            })->toArray();
        }

        $this->table($headers, $rows);
    }

    /**
     * JSON形式で出力
     */
    private function outputJson(\Illuminate\Support\Collection $models): void
    {
        $data = $models->map(function ($model) {
            return $model->toArray();
        })->toArray();

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
