<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use CattyNeo\LaravelGenAI\Exceptions\ProviderException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fetcherの共通機能を実装するAbstractクラス
 */
abstract class AbstractFetcher implements FetcherInterface
{
    protected HttpFactory $http;

    protected array $config;

    protected string $apiKey;

    public function __construct(HttpFactory $http, array $config)
    {
        $this->http = $http;
        $this->config = $config;
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->config['models_endpoint']);
    }

    /**
     * HTTPリクエストを実行し、レスポンスを返す
     */
    protected function makeRequest(string $endpoint, array $queryParams = []): Response
    {
        if (! $this->isAvailable()) {
            throw new ProviderException("Provider {$this->getProviderName()} is not properly configured");
        }

        try {
            $headers = $this->prepareHeaders();
            $client = $this->http->withHeaders($headers);

            if (! empty($queryParams)) {
                $client = $client->withOptions(['query' => $queryParams]);
            }

            Log::info("Fetching models from {$this->getProviderName()}", [
                'endpoint' => $endpoint,
                'provider' => $this->getProviderName(),
            ]);

            $response = $client->get($endpoint);

            if (! $response->successful()) {
                throw new ProviderException(
                    "Failed to fetch models from {$this->getProviderName()}: ".$response->body(),
                    $response->status()
                );
            }

            return $response;
        } catch (\Exception $e) {
            Log::error("Error fetching models from {$this->getProviderName()}", [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
            ]);

            throw new ProviderException(
                "Error fetching models from {$this->getProviderName()}: ".$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * APIキーを含むヘッダーを準備
     */
    protected function prepareHeaders(): array
    {
        $headers = $this->config['headers'] ?? [];

        foreach ($headers as $key => $value) {
            if (str_contains($value, '{api_key}')) {
                $headers[$key] = str_replace('{api_key}', $this->apiKey, $value);
            }
        }

        return $headers;
    }

    /**
     * クエリパラメータを準備
     */
    protected function prepareQueryParams(): array
    {
        $queryParams = $this->config['query_params'] ?? [];

        foreach ($queryParams as $key => $value) {
            if (str_contains($value, '{api_key}')) {
                $queryParams[$key] = str_replace('{api_key}', $this->apiKey, $value);
            }
        }

        return $queryParams;
    }

    /**
     * プロバイダー固有のレスポンス解析を実装
     */
    abstract protected function parseModelsResponse(array $data): Collection;

    /**
     * プロバイダー固有の単一モデルレスポンス解析を実装
     */
    abstract protected function parseModelResponse(array $data): ModelInfo;
}
