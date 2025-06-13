<?php

namespace CattyNeo\LaravelGenAI\Services\GenAI\Fetcher;

use CattyNeo\LaravelGenAI\Data\ModelInfo;
use Illuminate\Support\Collection;

/**
 * プロバイダーのモデル情報を取得するFetcherのインターフェース
 */
interface FetcherInterface
{
    /**
     * 利用可能なモデル一覧を取得
     *
     * @return Collection<ModelInfo>
     */
    public function fetchModels(): Collection;

    /**
     * 特定のモデル詳細情報を取得
     *
     * @param string $modelId
     * @return ModelInfo|null
     */
    public function fetchModel(string $modelId): ?ModelInfo;

    /**
     * プロバイダー名を取得
     *
     * @return string
     */
    public function getProviderName(): string;

    /**
     * APIが利用可能かチェック
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
