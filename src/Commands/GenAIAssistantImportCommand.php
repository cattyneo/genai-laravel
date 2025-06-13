<?php

namespace CattyNeo\LaravelGenAI\Commands;

use CattyNeo\LaravelGenAI\Services\GenAI\AssistantImportService;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIAssistantFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * OpenAI AssistantsをGenAIパッケージ形式でインポートするコマンド
 */
class GenAIAssistantImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genai:assistant-import
                            {--id=* : 特定のAssistant IDをインポート}
                            {--list : 利用可能なAssistant一覧を表示}
                            {--all : 全てのAssistantsをインポート}
                            {--status : インポート済みファイルの状況を表示}
                            {--cleanup : インポート済みファイルをクリーンアップ}
                            {--dry-run : 実際の操作は行わず、プレビューのみ表示}
                            {--force : 既存ファイルを上書き}';

    /**
     * The console command description.
     */
    protected $description = 'OpenAI Playground/Assistants BuilderからAssistantsをインポート';

    private AssistantImportService $importService;

    private OpenAIAssistantFetcher $fetcher;

    public function __construct(AssistantImportService $importService, OpenAIAssistantFetcher $fetcher)
    {
        parent::__construct();
        $this->importService = $importService;
        $this->fetcher = $fetcher;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->fetcher->isAvailable()) {
            $this->error('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->showAssistantsList();
        }

        if ($this->option('status')) {
            return $this->showImportStatus();
        }

        if ($this->option('cleanup')) {
            return $this->cleanupImportedFiles();
        }

        if ($this->option('all')) {
            return $this->importAllAssistants();
        }

        $assistantIds = $this->option('id');
        if (! empty($assistantIds)) {
            return $this->importSpecificAssistants($assistantIds);
        }

        $this->info('Please specify an option. Use --help for available options.');

        return self::SUCCESS;
    }

    /**
     * 利用可能なAssistant一覧を表示
     */
    private function showAssistantsList(): int
    {
        $this->info('🔍 Fetching available OpenAI Assistants...');

        try {
            $assistants = $this->fetcher->fetchAssistants();

            if ($assistants->isEmpty()) {
                $this->warn('No Assistants found in your OpenAI account.');

                return self::SUCCESS;
            }

            $this->info("Found {$assistants->count()} Assistant(s):");
            $this->newLine();

            $tableData = $assistants->map(function ($assistant) {
                return [
                    'ID' => $assistant->id,
                    'Name' => Str::limit($assistant->name, 30),
                    'Model' => $assistant->model,
                    'Tools' => count($assistant->tools),
                    'Files' => count($assistant->fileIds),
                    'Created' => $assistant->createdAt?->format('Y-m-d H:i'),
                ];
            })->toArray();

            $this->table(['ID', 'Name', 'Model', 'Tools', 'Files', 'Created'], $tableData);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to fetch Assistants: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * インポート状況を表示
     */
    private function showImportStatus(): int
    {
        $status = $this->importService->getImportedFiles();

        $this->info('📊 Import Status:');
        $this->line("  Prompts: {$status['prompts_count']} files");
        $this->line("  Presets: {$status['presets_count']} files");
        $this->newLine();

        if (! empty($status['prompts'])) {
            $this->info('Imported Prompts:');
            foreach ($status['prompts'] as $file) {
                $this->line("  - {$file}");
            }
            $this->newLine();
        }

        if (! empty($status['presets'])) {
            $this->info('Imported Presets:');
            foreach ($status['presets'] as $file) {
                $this->line("  - {$file}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * インポート済みファイルをクリーンアップ
     */
    private function cleanupImportedFiles(): int
    {
        $dryRun = $this->option('dry-run');

        if (! $dryRun && ! $this->confirm('Are you sure you want to delete all imported files?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->info($dryRun ? '🧹 Cleanup Preview (Dry Run):' : '🧹 Cleaning up imported files...');

        $results = $this->importService->cleanup($dryRun);

        if (! empty($results['removed'])) {
            $this->info('Files to be removed:');
            foreach ($results['removed'] as $file) {
                $this->line("  - {$file}");
            }
        }

        if (! empty($results['errors'])) {
            $this->error('Errors encountered:');
            foreach ($results['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        if (! $dryRun) {
            $this->info('✅ Cleanup completed.');
        }

        return self::SUCCESS;
    }

    /**
     * 全てのAssistantsをインポート
     */
    private function importAllAssistants(): int
    {
        $this->info('📥 Importing all OpenAI Assistants...');

        if ($this->option('dry-run')) {
            $this->warn('🔍 Dry run mode - no files will be created.');
        }

        try {
            $assistants = $this->fetcher->fetchAssistants();

            if ($assistants->isEmpty()) {
                $this->warn('No Assistants found to import.');

                return self::SUCCESS;
            }

            $this->info("Found {$assistants->count()} Assistant(s) to import.");
            $this->newLine();

            if (! $this->option('dry-run')) {
                $results = $this->importService->importAll();
                $this->displayImportResults($results);
            } else {
                $this->previewImport($assistants);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * 特定のAssistantsをインポート
     */
    private function importSpecificAssistants(array $assistantIds): int
    {
        $this->info('📥 Importing specific Assistants: '.implode(', ', $assistantIds));

        if ($this->option('dry-run')) {
            $this->warn('🔍 Dry run mode - no files will be created.');
        }

        try {
            if (! $this->option('dry-run')) {
                $results = $this->importService->importByIds($assistantIds);
                $this->displayImportResults($results);
            } else {
                foreach ($assistantIds as $assistantId) {
                    $assistant = $this->fetcher->fetchAssistant($assistantId);
                    if ($assistant) {
                        $this->line("Would import: {$assistant->name} ({$assistant->id})");
                    } else {
                        $this->error("Assistant not found: {$assistantId}");
                    }
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * インポート結果を表示
     */
    private function displayImportResults($results): void
    {
        $successful = $results->where('success', true);
        $failed = $results->where('success', false);

        $this->info("✅ Successfully imported: {$successful->count()}");

        if ($failed->count() > 0) {
            $this->error("❌ Failed to import: {$failed->count()}");
        }

        $this->newLine();

        // 成功した項目の詳細
        if ($successful->count() > 0) {
            $this->info('Import Summary:');
            foreach ($successful as $result) {
                $this->line("  ✅ {$result['name']} ({$result['assistant_id']})");
                $this->line("     Files: {$result['files_attached']}, Tools: {$result['tools_count']}");
            }
        }

        // 失敗した項目の詳細
        if ($failed->count() > 0) {
            $this->newLine();
            $this->error('Failed Imports:');
            foreach ($failed as $result) {
                $this->line("  ❌ {$result['name']} ({$result['assistant_id']})");
                $this->line("     Error: {$result['error']}");
            }
        }
    }

    /**
     * インポートプレビューを表示
     */
    private function previewImport($assistants): void
    {
        $this->info('Preview - Assistants to be imported:');
        foreach ($assistants as $assistant) {
            $this->line("  - {$assistant->name} ({$assistant->id})");
            $this->line("    Model: {$assistant->model}");
            $this->line('    Tools: '.count($assistant->tools));
            $this->line('    Files: '.count($assistant->fileIds));
            $this->newLine();
        }
    }
}
