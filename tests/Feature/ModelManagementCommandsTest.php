<?php

namespace CattyNeo\LaravelGenAI\Tests\Feature;

use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

/**
 * Artisanコマンドのテスト
 */
class ModelManagementCommandsTest extends TestCase
{
    private string $testYamlPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testYamlPath = storage_path('genai/test_models.yaml');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testYamlPath)) {
            File::delete($this->testYamlPath);
        }
        parent::tearDown();
    }

    public function test_model_list_command_with_no_yaml()
    {
        $this->artisan('genai:model-list')
            ->expectsOutputToContain('モデルが見つかりませんでした')
            ->assertExitCode(0);
    }

    public function test_model_list_command_with_yaml()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-list', ['--source' => 'yaml'])
            ->expectsOutputToContain('GenAI Models List')
            ->expectsOutputToContain('gpt-4o')
            ->expectsOutputToContain('claude-3-opus')
            ->expectsOutputToContain('総計: 2 モデル')
            ->assertExitCode(0);
    }

    public function test_model_list_command_with_details()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-list', ['--details' => true, '--source' => 'yaml'])
            ->expectsOutputToContain('Max Tokens')
            ->expectsOutputToContain('Context Window')
            ->expectsOutputToContain('Features')
            ->assertExitCode(0);
    }

    public function test_model_list_command_with_provider_filter()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-list', ['--provider' => 'openai', '--source' => 'yaml'])
            ->expectsOutputToContain('gpt-4o')
            ->expectsOutputToContain('Provider: OPENAI')
            ->doesntExpectOutputToContain('claude-3-opus')
            ->assertExitCode(0);
    }

    public function test_model_list_command_with_json_format()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-list', ['--format' => 'json', '--source' => 'yaml'])
            ->expectsOutputToContain('"id"')
            ->expectsOutputToContain('"provider"')
            ->expectsOutputToContain('"gpt-4o"')
            ->assertExitCode(0);
    }

    public function test_model_add_command_success()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-add', [
            'provider' => 'openai',
            'model' => 'gpt-5',
            '--name' => 'GPT-5',
            '--type' => 'text',
            '--features' => ['vision', 'reasoning'],
            '--max-tokens' => 32000,
            '--pricing-input' => '2.50',
            '--pricing-output' => '10.00',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('追加予定モデル情報')
            ->expectsOutputToContain('gpt-5')
            ->expectsOutputToContain('GPT-5')
            ->expectsOutputToContain('Dry-runモードです')
            ->assertExitCode(0);
    }

    public function test_model_add_command_with_invalid_provider()
    {
        $this->artisan('genai:model-add', [
            'provider' => 'invalid',
            'model' => 'test-model',
        ])
            ->expectsOutputToContain('無効なプロバイダー')
            ->assertExitCode(1);
    }

    public function test_model_add_command_with_existing_model()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-add', [
            'provider' => 'openai',
            'model' => 'gpt-4o',  // 既存モデル
        ])
            ->expectsOutputToContain('既に存在します')
            ->assertExitCode(1);
    }

    public function test_model_validate_command_with_valid_yaml()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-validate')
            ->expectsOutputToContain('GenAI Models YAML Validation')
            ->expectsOutputToContain('YAML構文: 正常')
            ->expectsOutputToContain('検証完了: すべてのチェックに合格')
            ->assertExitCode(0);
    }

    public function test_model_validate_command_with_missing_file()
    {
        $this->artisan('genai:model-validate')
            ->expectsOutputToContain('YAML file not found')
            ->assertExitCode(1);
    }

    public function test_model_validate_command_with_verbose()
    {
        $this->createTestYamlFile();

        $this->artisan('genai:model-validate', ['--verbose' => true])
            ->expectsOutputToContain('読み込まれたモデル:')
            ->expectsOutputToContain('openai: 1 モデル')
            ->expectsOutputToContain('claude: 1 モデル')
            ->assertExitCode(0);
    }

    public function test_preset_generate_command_default()
    {
        $this->artisan('genai:preset-generate', [
            'name' => 'test-preset',
        ])
            ->expectsOutputToContain('プリセット \'test-preset\' を生成しました')
            ->expectsOutputToContain('name: "test-preset"')
            ->expectsOutputToContain('description:')
            ->expectsOutputToContain('temperature: 0.7')
            ->assertExitCode(0);

        // ファイルが作成されたことを確認
        $presetPath = storage_path('genai/presets/test-preset.yaml');
        $this->assertTrue(File::exists($presetPath));

        // クリーンアップ
        File::delete($presetPath);
    }

    public function test_preset_generate_command_creative_template()
    {
        $this->artisan('genai:preset-generate', [
            'name' => 'creative-preset',
            '--template' => 'creative',
            '--provider' => 'openai',
            '--model' => 'gpt-4o',
        ])
            ->expectsOutputToContain('プリセット \'creative-preset\' を生成しました')
            ->expectsOutputToContain('Template: creative')
            ->expectsOutputToContain('provider: "openai"')
            ->expectsOutputToContain('model: "gpt-4o"')
            ->expectsOutputToContain('temperature: 1')
            ->expectsOutputToContain('クリエイティブな作業用の設定')
            ->assertExitCode(0);

        // クリーンアップ
        $presetPath = storage_path('genai/presets/creative-preset.yaml');
        if (File::exists($presetPath)) {
            File::delete($presetPath);
        }
    }

    public function test_preset_generate_command_existing_file_without_overwrite()
    {
        // 既存ファイルを作成
        $presetPath = storage_path('genai/presets/existing-preset.yaml');
        File::ensureDirectoryExists(dirname($presetPath));
        File::put($presetPath, 'existing content');

        $this->artisan('genai:preset-generate', [
            'name' => 'existing-preset',
        ])
            ->expectsOutputToContain('既に存在します')
            ->expectsOutputToContain('--overwrite オプション')
            ->assertExitCode(1);

        // クリーンアップ
        File::delete($presetPath);
    }

    public function test_preset_generate_command_with_overwrite()
    {
        // 既存ファイルを作成
        $presetPath = storage_path('genai/presets/overwrite-preset.yaml');
        File::ensureDirectoryExists(dirname($presetPath));
        File::put($presetPath, 'old content');

        $this->artisan('genai:preset-generate', [
            'name' => 'overwrite-preset',
            '--overwrite' => true,
        ])
            ->expectsOutputToContain('プリセット \'overwrite-preset\' を生成しました')
            ->assertExitCode(0);

        // 新しい内容に更新されていることを確認
        $content = File::get($presetPath);
        $this->assertStringContainsString('name: "overwrite-preset"', $content);
        $this->assertStringNotContainsString('old content', $content);

        // クリーンアップ
        File::delete($presetPath);
    }

    public function test_all_template_types()
    {
        $templates = ['default', 'creative', 'analytical', 'coding'];

        foreach ($templates as $template) {
            $name = "test-{$template}";

            $this->artisan('genai:preset-generate', [
                'name' => $name,
                '--template' => $template,
            ])
                ->expectsOutputToContain("プリセット '{$name}' を生成しました")
                ->assertExitCode(0);

            // ファイルが作成されたことを確認
            $presetPath = storage_path("genai/presets/{$name}.yaml");
            $this->assertTrue(File::exists($presetPath));

            $content = File::get($presetPath);
            $this->assertStringContainsString("Template: {$template}", $content);

            // クリーンアップ
            File::delete($presetPath);
        }
    }

    /**
     * テスト用のYAMLファイルを作成
     */
    private function createTestYamlFile(): void
    {
        $yamlContent = <<<YAML
openai:
  gpt-4o:
    provider: openai
    model: gpt-4o
    type: text
    features:
      - vision
      - function_calling
    limits:
      max_tokens: 16384
      context_window: 1000000

claude:
  claude-3-opus:
    provider: claude
    model: claude-3-opus
    type: text
    features:
      - vision
      - reasoning
    limits:
      max_tokens: 4096
      context_window: 200000
YAML;

        // ModelRepositoryに同じパスを使わせる
        $repository = app(ModelRepository::class);
        $reflection = new \ReflectionClass($repository);
        $property = $reflection->getProperty('yamlPath');
        $property->setAccessible(true);
        $property->setValue($repository, $this->testYamlPath);

        File::ensureDirectoryExists(dirname($this->testYamlPath));
        File::put($this->testYamlPath, $yamlContent);
    }

    protected function getPackageProviders($app)
    {
        return [
            \CattyNeo\LaravelGenAI\GenAIServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('genai.cache.enabled', false); // テスト時はキャッシュ無効
    }
}
