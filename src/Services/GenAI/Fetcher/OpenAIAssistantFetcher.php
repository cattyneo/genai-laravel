<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use Carbon\Carbon;
use CattyNeo\LaravelGenAI\Data\AssistantInfo;
use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Assistants API用のFetcher
 * Playground/Assistants Builderで作成されたAssistantを取得
 */
class OpenAIAssistantFetcher implements FetcherInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'api_key' => config('genai.providers.openai.api_key'),
            'base_url' => config('genai.providers.openai.base_url', 'https://api.openai.com'),
            'timeout' => 30,
        ], $config);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    /**
     * FetcherInterface実装（モデル関連は空実装）
     */
    public function fetchModels(): Collection
    {
        return collect();
    }

    public function fetchModel(string $modelId): ?ModelInfo
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return ! empty($this->config['api_key']);
    }

    /**
     * 利用可能なAssistant一覧を取得
     */
    public function fetchAssistants(): Collection
    {
        $response = $this->makeAssistantRequest('/assistants');

        return $this->parseAssistantsResponse($response->json());
    }

    /**
     * 特定のAssistant詳細情報を取得
     */
    public function fetchAssistant(string $assistantId): ?AssistantInfo
    {
        try {
            $response = $this->makeAssistantRequest("/assistants/{$assistantId}");

            return $this->parseAssistantResponse($response->json());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * AssistantのFiles情報を取得
     */
    public function fetchAssistantFiles(string $assistantId): Collection
    {
        try {
            $response = $this->makeAssistantRequest("/assistants/{$assistantId}/files");

            return collect($response->json()['data'] ?? []);
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Vector Storeの情報を取得
     */
    public function fetchVectorStore(string $vectorStoreId): ?array
    {
        try {
            $response = $this->makeAssistantRequest("/vector_stores/{$vectorStoreId}");

            return $response->json();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseAssistantsResponse(array $data): Collection
    {
        if (! isset($data['data']) || ! is_array($data['data'])) {
            return collect();
        }

        return collect($data['data'])->map(function ($assistant) {
            return $this->parseAssistantData($assistant);
        })->filter();
    }

    protected function parseAssistantResponse(array $data): AssistantInfo
    {
        return $this->parseAssistantData($data);
    }

    /**
     * OpenAI APIのAssistantデータをAssistantInfoに変換
     */
    private function parseAssistantData(array $assistant): AssistantInfo
    {
        return new AssistantInfo(
            id: $assistant['id'],
            name: $assistant['name'] ?? 'Unnamed Assistant',
            description: $assistant['description'] ?? '',
            instructions: $assistant['instructions'] ?? '',
            model: $assistant['model'],
            tools: $assistant['tools'] ?? [],
            fileIds: $assistant['file_ids'] ?? [],
            metadata: $assistant['metadata'] ?? [],
            temperature: $assistant['temperature'] ?? null,
            topP: $assistant['top_p'] ?? null,
            responseFormat: $assistant['response_format'] ?? null,
            toolResources: $assistant['tool_resources'] ?? [],
            createdAt: isset($assistant['created_at']) ? Carbon::createFromTimestamp($assistant['created_at']) : null,
            updatedAt: isset($assistant['updated_at']) ? Carbon::createFromTimestamp($assistant['updated_at']) : null,
        );
    }

    /**
     * HTTPリクエストを実行（Assistant API専用）
     */
    protected function makeAssistantRequest(string $endpoint): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout($this->config['timeout'])
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->config['api_key'],
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2',
            ])->get($this->config['base_url'].'/v1'.$endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "OpenAI Assistants API request failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response;
    }
}
