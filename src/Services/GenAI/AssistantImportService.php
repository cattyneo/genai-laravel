<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use CattyNeo\LaravelGenAI\Data\AssistantInfo;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIAssistantFetcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * OpenAI AssistantsをGenAIパッケージ形式でインポートするサービス
 */
class AssistantImportService
{
    private OpenAIAssistantFetcher $fetcher;
    private string $promptsPath;
    private string $presetsPath;

    public function __construct(OpenAIAssistantFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
        $this->promptsPath = storage_path('genai/prompts/openai');
        $this->presetsPath = storage_path('genai/presets/openai');

        $this->ensureDirectoriesExist();
    }

    /**
     * 全てのAssistantsをインポート
     */
    public function importAll(): Collection
    {
        $assistants = $this->fetcher->fetchAssistants();
        $results = collect();

        foreach ($assistants as $assistant) {
            $result = $this->importAssistant($assistant);
            $results->push($result);
        }

        return $results;
    }

    /**
     * 特定のAssistantをIDでインポート
     */
    public function importById(string $assistantId): array
    {
        $assistant = $this->fetcher->fetchAssistant($assistantId);

        if (!$assistant) {
            return [
                'success' => false,
                'assistant_id' => $assistantId,
                'error' => 'Assistant not found'
            ];
        }

        return $this->importAssistant($assistant);
    }

    /**
     * 複数のAssistantをIDリストでインポート
     */
    public function importByIds(array $assistantIds): Collection
    {
        $results = collect();

        foreach ($assistantIds as $assistantId) {
            $result = $this->importById($assistantId);
            $results->push($result);
        }

        return $results;
    }

    /**
     * 単一のAssistantをインポート
     */
    public function importAssistant(AssistantInfo $assistant): array
    {
        try {
            $promptPath = $this->savePromptFile($assistant);
            $presetPath = $this->savePresetFile($assistant);

            Log::info("Imported OpenAI Assistant", [
                'assistant_id' => $assistant->id,
                'name' => $assistant->name,
                'prompt_file' => $promptPath,
                'preset_file' => $presetPath,
            ]);

            return [
                'success' => true,
                'assistant_id' => $assistant->id,
                'name' => $assistant->name,
                'prompt_file' => $promptPath,
                'preset_file' => $presetPath,
                'files_attached' => count($assistant->fileIds),
                'tools_count' => count($assistant->tools),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to import OpenAI Assistant", [
                'assistant_id' => $assistant->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'assistant_id' => $assistant->id,
                'name' => $assistant->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * プロンプトファイルを保存
     */
    private function savePromptFile(AssistantInfo $assistant): string
    {
        $filename = $this->sanitizeFilename($assistant->id) . '.md';
        $filepath = $this->promptsPath . '/' . $filename;

        $content = $assistant->toPromptMarkdown();
        File::put($filepath, $content);

        return $filepath;
    }

    /**
     * プリセットファイルを保存
     */
    private function savePresetFile(AssistantInfo $assistant): string
    {
        $filename = $this->sanitizeFilename($assistant->id) . '.yaml';
        $filepath = $this->presetsPath . '/' . $filename;

        $content = $assistant->toPresetYaml();
        File::put($filepath, $content);

        return $filepath;
    }

    /**
     * ファイル名を安全な形式に変換
     */
    private function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '_', $filename);
    }

    /**
     * 必要なディレクトリを作成
     */
    private function ensureDirectoriesExist(): void
    {
        if (!File::exists($this->promptsPath)) {
            File::makeDirectory($this->promptsPath, 0755, true);
        }

        if (!File::exists($this->presetsPath)) {
            File::makeDirectory($this->presetsPath, 0755, true);
        }
    }

    /**
     * 既存のインポート済みファイル一覧を取得
     */
    public function getImportedFiles(): array
    {
        $prompts = File::exists($this->promptsPath)
            ? collect(File::files($this->promptsPath))->map->getBasename()
            : collect();

        $presets = File::exists($this->presetsPath)
            ? collect(File::files($this->presetsPath))->map->getBasename()
            : collect();

        return [
            'prompts_count' => $prompts->count(),
            'presets_count' => $presets->count(),
            'prompts' => $prompts->toArray(),
            'presets' => $presets->toArray(),
        ];
    }

    /**
     * インポート済みファイルをクリーンアップ
     */
    public function cleanup(bool $dryRun = true): array
    {
        $results = ['removed' => [], 'errors' => []];

        if (File::exists($this->promptsPath)) {
            $files = File::files($this->promptsPath);
            foreach ($files as $file) {
                if (!$dryRun) {
                    try {
                        File::delete($file);
                        $results['removed'][] = $file->getBasename();
                    } catch (\Exception $e) {
                        $results['errors'][] = $file->getBasename() . ': ' . $e->getMessage();
                    }
                } else {
                    $results['removed'][] = $file->getBasename() . ' (dry run)';
                }
            }
        }

        if (File::exists($this->presetsPath)) {
            $files = File::files($this->presetsPath);
            foreach ($files as $file) {
                if (!$dryRun) {
                    try {
                        File::delete($file);
                        $results['removed'][] = $file->getBasename();
                    } catch (\Exception $e) {
                        $results['errors'][] = $file->getBasename() . ': ' . $e->getMessage();
                    }
                } else {
                    $results['removed'][] = $file->getBasename() . ' (dry run)';
                }
            }
        }

        return $results;
    }
}
