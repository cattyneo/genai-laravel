<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelReplacementService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use Illuminate\Validation\ValidationException;

/**
 * モデル推奨API コントローラー
 */
class ModelRecommendationController extends Controller
{
    public function __construct(
        private ModelReplacementService $replacementService,
        private ModelRepository $modelRepository
    ) {}

    /**
     * 代替モデル推奨API
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRecommendations(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'current_model' => 'required|string',
                'provider' => 'required|string',
                'use_case' => 'nullable|string|in:chat,coding,translation,content_generation,analysis,vision,general',
                'performance_requirements' => 'nullable|array',
                'performance_requirements.min_context_window' => 'nullable|integer|min:1000',
                'performance_requirements.min_max_tokens' => 'nullable|integer|min:100',
                'performance_requirements.max_cost_increase' => 'nullable|numeric|min:0|max:5',
                'limit' => 'nullable|integer|min:1|max:10',
            ]);

            $context = [
                'use_case' => $validated['use_case'] ?? 'general',
                'performance_requirements' => $validated['performance_requirements'] ?? [],
            ];

            $recommendations = $this->replacementService->findReplacements(
                $validated['current_model'],
                $validated['provider'],
                $context
            );

            $limit = $validated['limit'] ?? 5;
            $limitedRecommendations = array_slice($recommendations, 0, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'current_model' => [
                        'model' => $validated['current_model'],
                        'provider' => $validated['provider'],
                    ],
                    'context' => $context,
                    'recommendations' => $this->formatRecommendations($limitedRecommendations),
                    'meta' => [
                        'total_found' => count($recommendations),
                        'returned' => count($limitedRecommendations),
                        'generated_at' => now()->toISOString(),
                    ]
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * 廃止予定モデルの代替推奨API
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDeprecatedReplacements(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'deprecated_model' => 'required|string',
                'provider' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:10',
            ]);

            $recommendations = $this->replacementService->suggestReplacementsForDeprecated(
                $validated['deprecated_model'],
                $validated['provider']
            );

            $limit = $validated['limit'] ?? 3;
            $limitedRecommendations = array_slice($recommendations, 0, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'deprecated_model' => [
                        'model' => $validated['deprecated_model'],
                        'provider' => $validated['provider'],
                    ],
                    'recommendations' => $this->formatDeprecatedRecommendations($limitedRecommendations),
                    'migration_guidance' => $this->generateMigrationGuidance($limitedRecommendations),
                    'meta' => [
                        'total_found' => count($recommendations),
                        'returned' => count($limitedRecommendations),
                        'generated_at' => now()->toISOString(),
                    ]
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * モデル比較API
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function compareModels(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'models' => 'required|array|min:2|max:5',
                'models.*.model' => 'required|string',
                'models.*.provider' => 'required|string',
                'comparison_criteria' => 'nullable|array',
                'comparison_criteria.*' => 'string|in:cost,performance,features,reliability',
            ]);

            $comparisonData = [];
            $criteria = $validated['comparison_criteria'] ?? ['cost', 'performance', 'features'];

            foreach ($validated['models'] as $modelSpec) {
                $modelInfo = $this->modelRepository->getModelInfo($modelSpec['provider'], $modelSpec['model']);

                if ($modelInfo) {
                    $comparisonData[] = [
                        'model' => $modelSpec['model'],
                        'provider' => $modelSpec['provider'],
                        'info' => $modelInfo,
                        'scores' => $this->calculateComparisonScores($modelInfo, $criteria),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'models' => $comparisonData,
                    'comparison_criteria' => $criteria,
                    'recommendation' => $this->getComparisonRecommendation($comparisonData),
                    'meta' => [
                        'compared_models' => count($comparisonData),
                        'generated_at' => now()->toISOString(),
                    ]
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * 使用統計に基づく最適化推奨API
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getOptimizationRecommendations(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'days' => 'nullable|integer|min:1|max:365',
                'provider' => 'nullable|string',
                'use_case' => 'nullable|string',
                'optimization_goal' => 'nullable|string|in:cost,speed,quality,balance',
            ]);

            $days = $validated['days'] ?? 30;
            $optimizationGoal = $validated['optimization_goal'] ?? 'balance';

            // 使用統計分析
            $usageAnalysis = $this->analyzeUsagePatterns($validated, $days);

            // 最適化推奨
            $recommendations = $this->generateOptimizationRecommendations($usageAnalysis, $optimizationGoal);

            return response()->json([
                'success' => true,
                'data' => [
                    'analysis_period' => [
                        'days' => $days,
                        'start_date' => now()->subDays($days)->toDateString(),
                        'end_date' => now()->toDateString(),
                    ],
                    'optimization_goal' => $optimizationGoal,
                    'usage_analysis' => $usageAnalysis,
                    'recommendations' => $recommendations,
                    'meta' => [
                        'generated_at' => now()->toISOString(),
                    ]
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * 推奨結果をフォーマット
     */
    private function formatRecommendations(array $recommendations): array
    {
        return array_map(function ($recommendation) {
            return [
                'model' => [
                    'id' => $recommendation['model']['id'] ?? '',
                    'model' => $recommendation['model']['model'] ?? '',
                    'provider' => $recommendation['model']['provider'] ?? '',
                    'type' => $recommendation['model']['type'] ?? '',
                    'features' => $recommendation['model']['features'] ?? [],
                ],
                'scores' => [
                    'total_score' => round($recommendation['total_score'], 3),
                    'cost_score' => round($recommendation['cost_score'], 3),
                    'performance_score' => round($recommendation['performance_score'], 3),
                    'cost_efficiency' => round($recommendation['cost_efficiency'], 3),
                    'features_match' => round($recommendation['features_match'], 3),
                ],
                'benefits' => ['Cost reduction', 'Better performance', 'Enhanced features'],
                'considerations' => ['Migration effort required', 'API compatibility check needed'],
            ];
        }, $recommendations);
    }

    /**
     * 廃止予定モデル推奨結果をフォーマット
     */
    private function formatDeprecatedRecommendations(array $recommendations): array
    {
        return array_map(function ($recommendation) {
            $formatted = $this->formatRecommendations([$recommendation])[0];

            $formatted['migration'] = [
                'complexity' => $recommendation['migration_complexity'] ?? 'unknown',
                'business_impact' => $recommendation['business_impact'] ?? 'unknown',
                'estimated_effort' => $this->estimateMigrationEffort($recommendation),
            ];

            return $formatted;
        }, $recommendations);
    }

    /**
     * 移行ガイダンス生成
     */
    private function generateMigrationGuidance(array $recommendations): array
    {
        if (empty($recommendations)) {
            return [];
        }

        $topRecommendation = $recommendations[0];

        return [
            'priority' => 'high',
            'timeline' => $this->suggestMigrationTimeline($topRecommendation),
            'steps' => $this->generateMigrationSteps($topRecommendation),
            'risks' => $this->identifyMigrationRisks($topRecommendation),
            'testing_strategy' => $this->suggestTestingStrategy($topRecommendation),
        ];
    }

    /**
     * 比較スコア計算
     */
    private function calculateComparisonScores(array $modelInfo, array $criteria): array
    {
        $scores = [];

        foreach ($criteria as $criterion) {
            $scores[$criterion] = match ($criterion) {
                'cost' => $this->calculateCostScore($modelInfo),
                'performance' => $this->calculatePerformanceScore($modelInfo),
                'features' => $this->calculateFeatureScore($modelInfo),
                'reliability' => $this->calculateReliabilityScore($modelInfo),
                default => 0.5
            };
        }

        return $scores;
    }

    /**
     * 比較推奨結果生成
     */
    private function getComparisonRecommendation(array $comparisonData): array
    {
        if (empty($comparisonData)) {
            return [];
        }

        // 総合スコアで最高のモデルを推奨
        $bestModel = collect($comparisonData)->sortByDesc(function ($model) {
            return array_sum($model['scores']);
        })->first();

        return [
            'recommended_model' => $bestModel['model'],
            'provider' => $bestModel['provider'],
            'reason' => $this->generateRecommendationReason($bestModel, $comparisonData),
            'confidence' => $this->calculateConfidenceScore($bestModel, $comparisonData),
        ];
    }

    /**
     * 使用パターン分析
     */
    private function analyzeUsagePatterns(array $filters, int $days): array
    {
        // 実装簡略化
        return [
            'total_requests' => 1000,
            'cost_breakdown' => ['openai' => 120.50, 'claude' => 80.25],
            'performance_metrics' => ['avg_duration' => 2500, 'success_rate' => 95.2],
            'usage_trends' => ['increasing' => true, 'peak_hours' => [9, 14, 16]],
        ];
    }

    /**
     * 最適化推奨生成
     */
    private function generateOptimizationRecommendations(array $usageAnalysis, string $optimizationGoal): array
    {
        // 実装簡略化
        return [
            [
                'type' => 'model_switch',
                'current_model' => 'gpt-4o',
                'recommended_model' => 'gpt-4o-mini',
                'potential_savings' => '25%',
                'impact' => 'minimal',
            ],
            [
                'type' => 'caching_optimization',
                'description' => 'Enable caching for repeated queries',
                'potential_savings' => '15%',
                'implementation_effort' => 'low',
            ],
        ];
    }

    // 以下、ヘルパーメソッド群（実装簡略化）
    private function generateBenefits(array $recommendation): array
    {
        return ['Cost reduction', 'Better performance', 'Enhanced features'];
    }

    private function generateConsiderations(array $recommendation): array
    {
        return ['Migration effort required', 'API compatibility check needed'];
    }

    private function estimateMigrationEffort(array $recommendation): string
    {
        return 'medium';
    }

    private function suggestMigrationTimeline(array $recommendation): string
    {
        return '2-3 weeks';
    }

    private function generateMigrationSteps(array $recommendation): array
    {
        return ['1. Setup test environment', '2. Update configurations', '3. Run compatibility tests'];
    }

    private function identifyMigrationRisks(array $recommendation): array
    {
        return ['API response format changes', 'Performance differences'];
    }

    private function suggestTestingStrategy(array $recommendation): array
    {
        return ['A/B testing', 'Shadow deployment', 'Gradual rollout'];
    }

    private function calculateCostScore(array $modelInfo): float
    {
        return 0.8; // 簡略化
    }

    private function calculatePerformanceScore(array $modelInfo): float
    {
        return 0.7; // 簡略化
    }

    private function calculateFeatureScore(array $modelInfo): float
    {
        return 0.9; // 簡略化
    }

    private function calculateReliabilityScore(array $modelInfo): float
    {
        return 0.85; // 簡略化
    }

    private function generateRecommendationReason(array $bestModel, array $comparisonData): string
    {
        return 'Best overall balance of cost, performance, and features';
    }

    private function calculateConfidenceScore(array $bestModel, array $comparisonData): float
    {
        return 0.85; // 簡略化
    }
}
