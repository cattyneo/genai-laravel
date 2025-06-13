<?php

namespace Tests\Unit\Services;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Data\AssistantInfo;
use CattyNeo\LaravelGenAI\Services\GenAI\AssistantImportService;
use CattyNeo\LaravelGenAI\Services\GenAI\Fetcher\OpenAIAssistantFetcher;
use CattyNeo\LaravelGenAI\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;

class AssistantImportServiceTest extends TestCase
{
    private AssistantImportService $service;

    /** @var MockInterface&OpenAIAssistantFetcher */
    private MockInterface $mockFetcher;

    private string $testPromptsPath;

    private string $testPresetsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockFetcher = Mockery::mock(OpenAIAssistantFetcher::class);
        $this->service = new AssistantImportService($this->mockFetcher);

        $this->testPromptsPath = storage_path('genai/prompts/openai');
        $this->testPresetsPath = storage_path('genai/presets/openai');

        // テスト用ディレクトリをクリーンアップ
        if (File::exists($this->testPromptsPath)) {
            File::deleteDirectory($this->testPromptsPath);
        }
        if (File::exists($this->testPresetsPath)) {
            File::deleteDirectory($this->testPresetsPath);
        }
    }

    protected function tearDown(): void
    {
        // テスト後にクリーンアップ
        if (File::exists($this->testPromptsPath)) {
            File::deleteDirectory($this->testPromptsPath);
        }
        if (File::exists($this->testPresetsPath)) {
            File::deleteDirectory($this->testPresetsPath);
        }

        parent::tearDown();
    }

    public function test_import_assistant_creates_files_successfully()
    {
        $assistant = new AssistantInfo(
            id: 'asst_test123',
            name: 'Test Assistant',
            description: 'Test description',
            instructions: 'You are a helpful assistant for {{topic}}.',
            model: 'gpt-4.1',
            tools: [
                ['type' => 'code_interpreter'],
                ['type' => 'file_search'],
            ],
            fileIds: ['file-abc123'],
            metadata: ['version' => '1.0'],
            temperature: 0.7,
            topP: 0.9,
            createdAt: Carbon::now()
        );

        $result = $this->service->importAssistant($assistant);

        $this->assertTrue($result['success']);
        $this->assertEquals('asst_test123', $result['assistant_id']);
        $this->assertEquals('Test Assistant', $result['name']);
        $this->assertEquals(1, $result['files_attached']);
        $this->assertEquals(2, $result['tools_count']);

        // ファイルが作成されたことを確認
        $this->assertTrue(File::exists($this->testPromptsPath.'/asst_test123.md'));
        $this->assertTrue(File::exists($this->testPresetsPath.'/asst_test123.yaml'));

        // プロンプトファイルの内容を確認
        $promptContent = File::get($this->testPromptsPath.'/asst_test123.md');
        $this->assertStringContainsString('title: Test Assistant', $promptContent);
        $this->assertStringContainsString('description: Test description', $promptContent);
        $this->assertStringContainsString('variables: [topic]', $promptContent);
        $this->assertStringContainsString('You are a helpful assistant for {{topic}}.', $promptContent);

        // プリセットファイルの内容を確認
        $presetContent = File::get($this->testPresetsPath.'/asst_test123.yaml');
        $this->assertStringContainsString('provider: openai', $presetContent);
        $this->assertStringContainsString('model: gpt-4.1', $presetContent);
        $this->assertStringContainsString('temperature: 0.7', $presetContent);
        $this->assertStringContainsString('top_p: 0.9', $presetContent);
        $this->assertStringContainsString('# Tools configured:', $presetContent);
        $this->assertStringContainsString('#   - code_interpreter', $presetContent);
        $this->assertStringContainsString('#   - file_search', $presetContent);
        $this->assertStringContainsString('# Files attached:', $presetContent);
        $this->assertStringContainsString('#   - file-abc123', $presetContent);
    }

    public function test_import_by_id_returns_not_found_for_invalid_id()
    {
        $this->mockFetcher
            ->shouldReceive('fetchAssistant')
            ->with('invalid_id')
            ->andReturn(null);

        $result = $this->service->importById('invalid_id');

        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_id', $result['assistant_id']);
        $this->assertEquals('Assistant not found', $result['error']);
    }

    public function test_import_by_id_succeeds_for_valid_id()
    {
        $assistant = new AssistantInfo(
            id: 'asst_valid123',
            name: 'Valid Assistant',
            description: 'Valid description',
            instructions: 'Help with coding.',
            model: 'gpt-4o',
            tools: [],
            fileIds: [],
            metadata: []
        );

        $this->mockFetcher
            ->shouldReceive('fetchAssistant')
            ->with('asst_valid123')
            ->andReturn($assistant);

        $result = $this->service->importById('asst_valid123');

        $this->assertTrue($result['success']);
        $this->assertEquals('asst_valid123', $result['assistant_id']);
        $this->assertEquals('Valid Assistant', $result['name']);
    }

    public function test_get_imported_files_returns_correct_counts()
    {
        // テストファイルを作成
        File::ensureDirectoryExists($this->testPromptsPath);
        File::ensureDirectoryExists($this->testPresetsPath);

        File::put($this->testPromptsPath.'/test1.md', 'test content');
        File::put($this->testPromptsPath.'/test2.md', 'test content');
        File::put($this->testPresetsPath.'/test1.yaml', 'test content');

        $status = $this->service->getImportedFiles();

        $this->assertEquals(2, $status['prompts_count']);
        $this->assertEquals(1, $status['presets_count']);
        $this->assertContains('test1.md', $status['prompts']);
        $this->assertContains('test2.md', $status['prompts']);
        $this->assertContains('test1.yaml', $status['presets']);
    }

    public function test_cleanup_dry_run_does_not_delete_files()
    {
        // テストファイルを作成
        File::ensureDirectoryExists($this->testPromptsPath);
        File::put($this->testPromptsPath.'/test.md', 'test content');

        $results = $this->service->cleanup(true); // dry run

        $this->assertContains('test.md (dry run)', $results['removed']);
        $this->assertTrue(File::exists($this->testPromptsPath.'/test.md')); // ファイルは残っている
    }

    public function test_cleanup_actually_deletes_files()
    {
        // テストファイルを作成
        File::ensureDirectoryExists($this->testPromptsPath);
        File::put($this->testPromptsPath.'/test.md', 'test content');

        $results = $this->service->cleanup(false); // actual cleanup

        $this->assertContains('test.md', $results['removed']);
        $this->assertFalse(File::exists($this->testPromptsPath.'/test.md')); // ファイルが削除されている
    }
}
