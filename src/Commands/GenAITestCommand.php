<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager;
use Illuminate\Console\Command;
use Exception;

class GenAITestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:test
                            {--provider= : Test specific provider (openai, gemini, claude, grok)}
                            {--model= : Test specific model}
                            {--prompt= : Custom test prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Test GenAI API connections and functionality';

    /**
     * Execute the console command.
     */
    public function handle(GenAIManager $genaiManager): int
    {
        $this->info('🧪 Testing GenAI package...');
        $this->newLine();

        $provider = $this->option('provider');
        $model = $this->option('model');
        $prompt = $this->option('prompt') ?? 'Hello! Please respond with "API connection successful" to confirm you can receive this message.';

        if ($provider) {
            return $this->testSpecificProvider($genaiManager, $provider, $model, $prompt);
        }

        return $this->testAllProviders($genaiManager, $prompt);
    }

    /**
     * すべてのプロバイダーをテスト
     */
    private function testAllProviders(GenAIManager $genaiManager, string $prompt): int
    {
        $providers = ['mock', 'openai', 'gemini', 'claude', 'grok'];
        $results = [];

        foreach ($providers as $provider) {
            $results[$provider] = $this->testProvider($genaiManager, $provider, null, $prompt);
        }

        $this->newLine();
        $this->displayTestResults($results);

        $successCount = count(array_filter($results));
        $totalCount = count($results);

        if ($successCount === 0) {
            $this->error('❌ All provider tests failed');
            return Command::FAILURE;
        }

        $this->info("✅ {$successCount}/{$totalCount} providers are working correctly");
        return Command::SUCCESS;
    }

    /**
     * 特定のプロバイダーをテスト
     */
    private function testSpecificProvider(GenAIManager $genaiManager, string $provider, ?string $model, string $prompt): int
    {
        $result = $this->testProvider($genaiManager, $provider, $model, $prompt);

        if ($result) {
            $this->info("✅ {$provider} is working correctly");
            return Command::SUCCESS;
        }

        $this->error("❌ {$provider} test failed");
        return Command::FAILURE;
    }

    /**
     * プロバイダーをテストし、結果を返す
     */
    private function testProvider(GenAIManager $genaiManager, string $provider, ?string $model, string $prompt): bool
    {
        $this->line("Testing {$provider}...");

        try {
            // 設定確認
            if (!$this->checkProviderConfig($provider)) {
                $this->warn("  ⚠️  API key not configured for {$provider}");
                return false;
            }

            // API呼び出しテスト
            $manager = $genaiManager->provider($provider);

            if ($model) {
                $manager = $manager->model($model);
            }

            $response = $manager->ask($prompt);

            if (empty($response)) {
                $this->error("  ❌ Empty response from {$provider}");
                return false;
            }

            $this->info("  ✅ {$provider} responded successfully");
            $this->line("  📝 Response: " . substr($response, 0, 100) . (strlen($response) > 100 ? '...' : ''));

            return true;
        } catch (Exception $e) {
            $this->error("  ❌ {$provider} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * プロバイダーの設定確認
     */
    private function checkProviderConfig(string $provider): bool
    {
        // Mockプロバイダーはテスト用なのでAPIキー不要
        if ($provider === 'mock') {
            return true;
        }

        $envKeys = [
            'openai' => 'OPENAI_API_KEY',
            'gemini' => 'GEMINI_API_KEY',
            'claude' => 'CLAUDE_API_KEY',
            'grok' => 'GROK_API_KEY',
        ];

        $envKey = $envKeys[$provider] ?? null;

        if (!$envKey) {
            return false;
        }

        return !empty(env($envKey));
    }

    /**
     * テスト結果を表示
     */
    private function displayTestResults(array $results): void
    {
        $this->line('📊 Test Results:');
        $this->newLine();

        $headers = ['Provider', 'Status', 'Details'];
        $rows = [];

        foreach ($results as $provider => $success) {
            $status = $success ? '✅ Pass' : '❌ Fail';
            $details = $success ? 'API connection successful' : 'Check API key and configuration';

            $rows[] = [ucfirst($provider), $status, $details];
        }

        $this->table($headers, $rows);
    }
}
