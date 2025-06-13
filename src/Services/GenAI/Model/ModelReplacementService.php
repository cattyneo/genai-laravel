<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI\Model;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 代替モデル探索サービス
 *
 * 機能互換性とコスト効率を考慮した最適なモデル代替を提案
 */
class ModelReplacementService
{
    public function __construct(
        private ModelRepository $modelRepository
    ) {}

    /**
     * 代替モデルを検索
     *
     * @param  string  $currentModel  現在のモデル
     * @param  string  $provider  プロバイダー
     * @param  array  $context  使用コンテキスト（use_case, performance_requirements等）
     * @return array 推奨代替モデルリスト（優先順位順）
     */
    public function findReplacements(
        string $currentModel,
        string $provider,
        array $context = []
    ): array {
        $currentModelInfo = $this->modelRepository->getModelInfo($provider, $currentModel);
        if (! $currentModelInfo) {
            return [];
        }

        $allModels = $this->modelRepository->getAllModels();

        // 1. 機能互換性でフィルタリング
        $compatibleModels = $this->filterByCompatibility($allModels, $currentModelInfo);

        // 2. 性能要件チェック
        $performanceMatched = $this->filterByPerformance($compatibleModels, $currentModelInfo, $context);

        // 3. コスト効率評価
        $costRanked = $this->rankByCostEfficiency($performanceMatched, $currentModelInfo, $context);

        // 4. 実使用データ分析による最適化
        $optimized = $this->optimizeByUsageAnalytics($costRanked, $currentModel, $context);

        // 5. 信頼性・安全性チェック
        $validated = $this->validateReliability($optimized);

        return array_slice($validated, 0, 5); // トップ5を返す
    }

    /**
     * 機能互換性フィルタリング
     */
    private function filterByCompatibility(Collection $models, array $currentModel): Collection
    {
        $requiredFeatures = $currentModel['features'] ?? [];
        $requiredType = $currentModel['type'] ?? 'text';

        return $models->filter(function ($model) use ($requiredFeatures, $requiredType) {
            // タイプマッチング
            if (($model['type'] ?? 'text') !== $requiredType) {
                return false;
            }

            // 必須機能チェック
            $modelFeatures = $model['features'] ?? [];
            foreach ($requiredFeatures as $feature) {
                if (! in_array($feature, $modelFeatures)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * 性能要件フィルタリング
     */
    private function filterByPerformance(
        Collection $models,
        array $currentModel,
        array $context
    ): Collection {
        $currentLimits = $currentModel['limits'] ?? [];
        $performanceReqs = $context['performance_requirements'] ?? [];

        return $models->filter(function ($model) use ($currentLimits, $performanceReqs) {
            $modelLimits = $model['limits'] ?? [];

            // コンテキストウィンドウ
            if (isset($currentLimits['context_window']) && isset($modelLimits['context_window'])) {
                $requiredWindow = $performanceReqs['min_context_window'] ?? $currentLimits['context_window'];
                if ($modelLimits['context_window'] < $requiredWindow * 0.8) { // 20%のマージン
                    return false;
                }
            }

            // 最大トークン数
            if (isset($currentLimits['max_tokens']) && isset($modelLimits['max_tokens'])) {
                $requiredTokens = $performanceReqs['min_max_tokens'] ?? $currentLimits['max_tokens'];
                if ($modelLimits['max_tokens'] < $requiredTokens * 0.8) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * コスト効率評価とランキング
     */
    private function rankByCostEfficiency(
        Collection $models,
        array $currentModel,
        array $context
    ): array {
        $useCase = $context['use_case'] ?? 'general';
        $currentPricing = $currentModel['pricing'] ?? [];

        $scoredModels = $models->map(function ($model) use ($currentPricing, $useCase) {
            $modelPricing = $model['pricing'] ?? [];

            // コスト効率スコア計算
            $costScore = $this->calculateCostScore($modelPricing, $currentPricing, $useCase);

            // 性能スコア計算
            $performanceScore = $this->calculatePerformanceScore($model, $useCase);

            // 総合スコア（コスト70%、性能30%）
            $totalScore = ($costScore * 0.7) + ($performanceScore * 0.3);

            return [
                'model' => $model,
                'cost_score' => $costScore,
                'performance_score' => $performanceScore,
                'total_score' => $totalScore,
                'cost_efficiency' => $this->calculateCostEfficiency($modelPricing, $currentPricing),
                'features_match' => $this->calculateFeatureMatch($model, $useCase),
            ];
        });

        return $scoredModels->sortByDesc('total_score')->values()->toArray();
    }

    /**
     * コストスコア計算
     */
    private function calculateCostScore(array $modelPricing, array $currentPricing, string $useCase): float
    {
        if (empty($modelPricing) || empty($currentPricing)) {
            return 0.5; // 不明な場合は中間値
        }

        // 使用ケース別の重み付け
        $weights = match ($useCase) {
            'chat', 'translation' => ['input' => 0.3, 'output' => 0.7], // 出力重視
            'coding', 'analysis' => ['input' => 0.5, 'output' => 0.5], // バランス
            'content_generation' => ['input' => 0.2, 'output' => 0.8], // 出力超重視
            default => ['input' => 0.4, 'output' => 0.6]
        };

        $modelInputCost = $modelPricing['input'] ?? 0;
        $modelOutputCost = $modelPricing['output'] ?? 0;
        $currentInputCost = $currentPricing['input'] ?? 0;
        $currentOutputCost = $currentPricing['output'] ?? 0;

        if ($currentInputCost == 0 && $currentOutputCost == 0) {
            return 0.5;
        }

        // 相対コスト効率計算
        $inputRatio = $currentInputCost > 0 ? min($modelInputCost / $currentInputCost, 2) : 1;
        $outputRatio = $currentOutputCost > 0 ? min($modelOutputCost / $currentOutputCost, 2) : 1;

        $weightedRatio = ($inputRatio * $weights['input']) + ($outputRatio * $weights['output']);

        // スコア逆転（コストが低いほど高スコア）
        return max(0, 1 - ($weightedRatio - 1) / 2);
    }

    /**
     * 性能スコア計算
     */
    private function calculatePerformanceScore(array $model, string $useCase): float
    {
        $features = $model['features'] ?? [];
        $limits = $model['limits'] ?? [];
        $provider = $model['provider'] ?? '';

        $score = 0.5; // ベーススコア

        // 機能ボーナス
        $featureBonus = match ($useCase) {
            'coding' => in_array('function_calling', $features) ? 0.2 : 0,
            'analysis' => in_array('reasoning', $features) ? 0.3 : 0,
            'content_generation' => in_array('structured_output', $features) ? 0.15 : 0,
            'vision' => in_array('vision', $features) ? 0.4 : 0,
            default => 0
        };

        // コンテキストウィンドウボーナス
        $contextWindow = $limits['context_window'] ?? 0;
        $contextBonus = match (true) {
            $contextWindow >= 1000000 => 0.2,  // 1M+
            $contextWindow >= 128000 => 0.15,  // 128K+
            $contextWindow >= 32000 => 0.1,    // 32K+
            default => 0
        };

        // プロバイダー信頼性ボーナス
        $providerBonus = match ($provider) {
            'openai' => 0.1,
            'claude' => 0.08,
            'gemini' => 0.06,
            'grok' => 0.04,
            default => 0
        };

        return min(1.0, $score + $featureBonus + $contextBonus + $providerBonus);
    }

    /**
     * 実使用データ分析による最適化
     */
    private function optimizeByUsageAnalytics(
        array $candidates,
        string $currentModel,
        array $context
    ): array {
        // 最近30日の使用パターン分析
        $usageStats = $this->analyzeUsagePatterns($currentModel, 30);

        return array_map(function ($candidate) use ($usageStats) {
            $model = $candidate['model'];

            // 実使用データによる調整
            $usageBonus = $this->calculateUsageBonus($model, $usageStats);

            $candidate['total_score'] += $usageBonus;
            $candidate['usage_bonus'] = $usageBonus;
            $candidate['usage_stats'] = $usageStats;

            return $candidate;
        }, $candidates);
    }

    /**
     * 使用パターン分析
     */
    private function analyzeUsagePatterns(string $currentModel, int $days): array
    {
        $startDate = Carbon::now()->subDays($days);

        $stats = DB::table('genai_requests')
            ->where('model', $currentModel)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                AVG(input_tokens) as avg_input_tokens,
                AVG(output_tokens) as avg_output_tokens,
                AVG(cost) as avg_cost,
                AVG(duration_ms) as avg_duration,
                COUNT(*) as total_requests,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating,
                use_case,
                COUNT(*) as use_case_count
            ')
            ->groupBy('use_case')
            ->get();

        return [
            'primary_use_cases' => $stats->pluck('use_case', 'use_case_count')->toArray(),
            'avg_cost_per_request' => $stats->avg('avg_cost'),
            'avg_response_time' => $stats->avg('avg_duration'),
            'avg_user_rating' => $stats->avg('avg_rating'),
            'total_requests' => $stats->sum('total_requests'),
            'avg_input_tokens' => $stats->avg('avg_input_tokens'),
            'avg_output_tokens' => $stats->avg('avg_output_tokens'),
        ];
    }

    /**
     * 使用統計ボーナス計算
     */
    private function calculateUsageBonus(array $model, array $usageStats): float
    {
        $bonus = 0;

        // 既存モデルと似た特性を持つモデルにボーナス
        $modelProvider = $model['provider'] ?? '';
        $modelType = $model['type'] ?? '';

        // プロバイダー類似性（同じプロバイダーは継続性でボーナス）
        if (isset($usageStats['primary_provider']) && $modelProvider === $usageStats['primary_provider']) {
            $bonus += 0.05;
        }

        return $bonus;
    }

    /**
     * 信頼性・安全性検証
     */
    private function validateReliability(array $candidates): array
    {
        return array_filter($candidates, function ($candidate) {
            $model = $candidate['model'];
            $provider = $model['provider'] ?? '';

            // 廃止予定モデルの除外
            if ($model['is_deprecated'] ?? false) {
                return false;
            }

            // 実験的モデルの慎重な評価
            $modelName = $model['model'] ?? '';
            if (str_contains($modelName, 'experimental') || str_contains($modelName, 'preview')) {
                // スコアが特に高い場合のみ許可
                return $candidate['total_score'] > 0.8;
            }

            // 最低スコア閾値
            return $candidate['total_score'] > 0.3;
        });
    }

    /**
     * コスト効率計算
     */
    private function calculateCostEfficiency(array $modelPricing, array $currentPricing): float
    {
        if (empty($modelPricing) || empty($currentPricing)) {
            return 1.0;
        }

        $modelTotal = ($modelPricing['input'] ?? 0) + ($modelPricing['output'] ?? 0);
        $currentTotal = ($currentPricing['input'] ?? 0) + ($currentPricing['output'] ?? 0);

        if ($currentTotal === 0) {
            return 1.0;
        }

        return $currentTotal / max($modelTotal, 0.001);
    }

    /**
     * 機能適合度計算
     */
    private function calculateFeatureMatch(array $model, string $useCase): float
    {
        $features = $model['features'] ?? [];

        $requiredFeatures = match ($useCase) {
            'coding' => ['function_calling', 'structured_output'],
            'analysis' => ['reasoning', 'function_calling'],
            'content_generation' => ['structured_output', 'streaming'],
            'vision' => ['vision', 'structured_output'],
            'translation' => ['streaming'],
            default => []
        };

        if (empty($requiredFeatures)) {
            return 1.0;
        }

        $matches = array_intersect($features, $requiredFeatures);

        return count($matches) / count($requiredFeatures);
    }

    /**
     * 廃止予定モデルの代替提案
     */
    public function suggestReplacementsForDeprecated(string $deprecatedModel, string $provider): array
    {
        $context = [
            'performance_requirements' => [
                'maintain_or_improve' => true,
                'cost_tolerance' => 1.2, // 20%のコスト増加まで許容
            ],
            'use_case' => $this->inferUseCaseFromModel($deprecatedModel),
        ];

        $replacements = $this->findReplacements($deprecatedModel, $provider, $context);

        // 廃止予定モデル特有の追加評価
        return array_map(function ($replacement) use ($deprecatedModel) {
            $replacement['migration_complexity'] = $this->assessMigrationComplexity(
                $deprecatedModel,
                $replacement['model']['model']
            );
            $replacement['business_impact'] = $this->assessBusinessImpact(
                $deprecatedModel,
                $replacement['model']
            );

            return $replacement;
        }, $replacements);
    }

    /**
     * モデル名から使用ケースを推測
     */
    private function inferUseCaseFromModel(string $model): string
    {
        return match (true) {
            str_contains($model, 'code') || str_contains($model, 'codex') => 'coding',
            str_contains($model, 'vision') || str_contains($model, 'image') => 'vision',
            str_contains($model, 'embedding') => 'embedding',
            str_contains($model, 'reasoning') || str_contains($model, 'o1') || str_contains($model, 'o3') => 'analysis',
            default => 'general'
        };
    }

    /**
     * 移行複雑度評価
     */
    private function assessMigrationComplexity(string $fromModel, string $toModel): string
    {
        // 同じプロバイダー内の移行は低複雑度
        // 異なるプロバイダーへの移行は高複雑度
        // API互換性、パラメータ差異等を考慮

        return 'low'; // 簡略化
    }

    /**
     * ビジネス影響度評価
     */
    private function assessBusinessImpact(string $fromModel, array $toModel): string
    {
        // コスト影響、性能影響、機能影響等を総合評価

        return 'minimal'; // 簡略化
    }
}
