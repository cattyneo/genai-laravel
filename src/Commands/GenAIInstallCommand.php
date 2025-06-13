<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Commands;

use Illuminate\Console\Command;

class GenAIInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:install
                            {--force : Overwrite existing files}
                            {--skip-migration : Skip running migrations}';

    /**
     * The console command description.
     */
    protected $description = 'Install the GenAI package';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Installing Laravel GenAI package...');

        // 設定ファイルを公開
        $this->publishConfig();

        // マイグレーションファイルを公開
        $this->publishMigrations();

        // プリセットファイルを公開
        $this->publishPresets();

        // プロンプトファイルを公開
        $this->publishPrompts();

        // マイグレーション実行
        if (! $this->option('skip-migration')) {
            $this->runMigrations();
        }

        // 環境変数の設定案内
        $this->showEnvironmentVariablesInfo();

        $this->info('✅ GenAI package installation completed!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('1. Set your API keys in .env file');
        $this->line('2. Run: php artisan genai:test to verify setup');
        $this->line('3. Check the documentation for usage examples');

        return Command::SUCCESS;
    }

    /**
     * 設定ファイルを公開
     */
    private function publishConfig(): void
    {
        $force = $this->option('force');

        $this->line('📝 Publishing configuration file...');

        $this->call('vendor:publish', [
            '--provider' => 'CattyNeo\LaravelGenAI\GenAIServiceProvider',
            '--tag' => 'genai-config',
            '--force' => $force,
        ]);
    }

    /**
     * マイグレーションファイルを公開
     */
    private function publishMigrations(): void
    {
        $force = $this->option('force');

        $this->line('🗄️  Publishing migration files...');

        $this->call('vendor:publish', [
            '--provider' => 'CattyNeo\LaravelGenAI\GenAIServiceProvider',
            '--tag' => 'genai-migrations',
            '--force' => $force,
        ]);
    }

    /**
     * プリセットファイルを公開
     */
    private function publishPresets(): void
    {
        $force = $this->option('force');

        $this->line('📋 Publishing preset files...');

        $this->call('vendor:publish', [
            '--provider' => 'CattyNeo\LaravelGenAI\GenAIServiceProvider',
            '--tag' => 'genai-presets',
            '--force' => $force,
        ]);
    }

    /**
     * プロンプトファイルを公開
     */
    private function publishPrompts(): void
    {
        $force = $this->option('force');

        $this->line('💬 Publishing prompt files...');

        $this->call('vendor:publish', [
            '--provider' => 'CattyNeo\LaravelGenAI\GenAIServiceProvider',
            '--tag' => 'genai-prompts',
            '--force' => $force,
        ]);
    }

    /**
     * マイグレーションを実行
     */
    private function runMigrations(): void
    {
        $this->line('🔄 Running migrations...');

        $this->call('migrate');
    }

    /**
     * 環境変数設定の案内
     */
    private function showEnvironmentVariablesInfo(): void
    {
        $this->newLine();
        $this->line('⚙️  Environment Variables Setup:');
        $this->line('Add the following to your .env file:');
        $this->newLine();

        $envVars = [
            'OPENAI_API_KEY=sk-...',
            'GEMINI_API_KEY=...',
            'CLAUDE_API_KEY=sk-ant-...',
            'GROK_API_KEY=xai-...',
            'GENAI_CACHE_DRIVER=redis',
            'GENAI_DEFAULT_PROVIDER=openai',
            'GENAI_DEFAULT_MODEL=gpt-4o-mini',
        ];

        foreach ($envVars as $var) {
            $this->line("  {$var}");
        }
    }
}
