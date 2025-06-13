<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use CattyNeo\LaravelGenAI\Services\GenAI\Model\ModelRepository;
use CattyNeo\LaravelGenAI\Services\GenAI\CostOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * GenAI Webhook API コントローラー
 */
class WebhookController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ModelRepository $modelRepository,
        private CostOptimizationService $costService
    ) {}

    /**
     * モデル更新Webhook処理
     */
    public function handleModelUpdate(Request $request): JsonResponse
    {
        try {
            // Webhook認証（必要に応じて）
            if (!$this->validateWebhookSignature($request)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature'
                ], 401);
            }

            $payload = $request->all();
            Log::info('Model update webhook received', $payload);

            $provider = $payload['provider'] ?? null;
            $updateType = $payload['type'] ?? 'model_update';
            $data = $payload['data'] ?? [];

            if (!$provider) {
                return response()->json([
                    'success' => false,
                    'error' => 'Provider is required'
                ], 400);
            }

            $response = match ($updateType) {
                'new_model' => $this->handleNewModel($provider, $data),
                'model_deprecation' => $this->handleModelDeprecation($provider, $data),
                'model_update' => $this->handleModelVersionUpdate($provider, $data),
                'pricing_update' => $this->handlePricingUpdate($provider, $data),
                default => $this->handleGenericUpdate($provider, $data),
            };

            return response()->json([
                'success' => true,
                'data' => $response,
                'meta' => [
                    'processed_at' => now()->toISOString(),
                    'webhook_type' => 'model_update',
                    'provider' => $provider,
                    'update_type' => $updateType,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Model update webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process model update webhook',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * コストアラートWebhook処理
     */
    public function handleCostAlert(Request $request): JsonResponse
    {
        try {
            // Webhook認証（必要に応じて）
            if (!$this->validateWebhookSignature($request)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature'
                ], 401);
            }

            $payload = $request->all();
            Log::info('Cost alert webhook received', $payload);

            $alertType = $payload['type'] ?? 'cost_threshold';
            $data = $payload['data'] ?? [];

            $response = match ($alertType) {
                'budget_exceeded' => $this->handleBudgetExceeded($data),
                'cost_spike' => $this->handleCostSpike($data),
                'usage_anomaly' => $this->handleUsageAnomaly($data),
                'cost_threshold' => $this->handleCostThreshold($data),
                default => $this->handleGenericCostAlert($data),
            };

            return response()->json([
                'success' => true,
                'data' => $response,
                'meta' => [
                    'processed_at' => now()->toISOString(),
                    'webhook_type' => 'cost_alert',
                    'alert_type' => $alertType,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Cost alert webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process cost alert webhook',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * システムアラートWebhook処理
     */
    public function handleSystemAlert(Request $request): JsonResponse
    {
        try {
            if (!$this->validateWebhookSignature($request)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature'
                ], 401);
            }

            $payload = $request->all();
            Log::info('System alert webhook received', $payload);

            $alertType = $payload['type'] ?? 'system_alert';
            $severity = $payload['severity'] ?? 'info';
            $data = $payload['data'] ?? [];

            // システムアラートの通知処理
            $this->notificationService->sendSystemAlert([
                'type' => $alertType,
                'severity' => $severity,
                'data' => $data,
                'timestamp' => now()->toISOString(),
                'source' => 'webhook',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'alert_processed' => true,
                    'notification_sent' => true,
                ],
                'meta' => [
                    'processed_at' => now()->toISOString(),
                    'webhook_type' => 'system_alert',
                    'alert_type' => $alertType,
                    'severity' => $severity,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('System alert webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process system alert webhook',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 汎用Webhook処理
     */
    public function handleGenericWebhook(Request $request, string $type): JsonResponse
    {
        try {
            if (!$this->validateWebhookSignature($request)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature'
                ], 401);
            }

            $payload = $request->all();
            Log::info("Generic webhook received: {$type}", $payload);

            // タイプに応じた処理を実行
            $response = $this->processGenericWebhook($type, $payload);

            return response()->json([
                'success' => true,
                'data' => $response,
                'meta' => [
                    'processed_at' => now()->toISOString(),
                    'webhook_type' => $type,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Generic webhook error: {$type}", [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => "Failed to process {$type} webhook",
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Private helper methods

    /**
     * Webhook署名検証
     */
    private function validateWebhookSignature(Request $request): bool
    {
        // 実装に応じてWebhookの署名検証を行う
        // 例: HMACシグネチャ、API キー検証など
        
        $signature = $request->header('X-Webhook-Signature');
        $secret = config('genai.webhook_secret');
        
        if (!$signature || !$secret) {
            return true; // 開発環境では無効化する場合
        }
        
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 新しいモデル追加処理
     */
    private function handleNewModel(string $provider, array $data): array
    {
        $modelData = $data['model'] ?? [];
        
        if (empty($modelData)) {
            throw new \InvalidArgumentException('Model data is required');
        }

        $added = $this->modelRepository->addNewModel($provider, $modelData);
        
        // 新モデル通知
        $this->notificationService->sendModelUpdateNotification(
            [$modelData], // new models
            [] // updated models
        );

        return [
            'action' => 'model_added',
            'model' => $added,
            'notification_sent' => true,
        ];
    }

    /**
     * モデル廃止処理
     */
    private function handleModelDeprecation(string $provider, array $data): array
    {
        $deprecatedModels = $data['deprecated_models'] ?? [];
        $replacementSuggestions = $data['replacement_suggestions'] ?? [];
        
        if (empty($deprecatedModels)) {
            throw new \InvalidArgumentException('Deprecated models data is required');
        }

        // モデルを廃止としてマーク
        foreach ($deprecatedModels as $modelId) {
            $this->modelRepository->markModelAsDeprecated($provider, $modelId);
        }
        
        // 廃止警告通知
        $this->notificationService->sendDeprecationWarning(
            $deprecatedModels,
            $replacementSuggestions
        );

        return [
            'action' => 'models_deprecated',
            'deprecated_count' => count($deprecatedModels),
            'notification_sent' => true,
        ];
    }

    /**
     * モデルバージョン更新処理
     */
    private function handleModelVersionUpdate(string $provider, array $data): array
    {
        $updates = $data['updates'] ?? [];
        
        if (empty($updates)) {
            throw new \InvalidArgumentException('Update data is required');
        }

        $updated = [];
        foreach ($updates as $update) {
            $updated[] = $this->modelRepository->updateModel($provider, $update);
        }

        return [
            'action' => 'models_updated',
            'updated_count' => count($updated),
            'models' => $updated,
        ];
    }

    /**
     * 価格更新処理
     */
    private function handlePricingUpdate(string $provider, array $data): array
    {
        $pricingData = $data['pricing'] ?? [];
        
        if (empty($pricingData)) {
            throw new \InvalidArgumentException('Pricing data is required');
        }

        $updated = $this->modelRepository->updatePricing($provider, $pricingData);

        return [
            'action' => 'pricing_updated',
            'updated_models' => count($updated),
            'pricing_effective_date' => $data['effective_date'] ?? now()->toDateString(),
        ];
    }

    /**
     * 汎用更新処理
     */
    private function handleGenericUpdate(string $provider, array $data): array
    {
        return [
            'action' => 'generic_update',
            'provider' => $provider,
            'data_received' => !empty($data),
            'processed' => true,
        ];
    }

    /**
     * 予算超過処理
     */
    private function handleBudgetExceeded(array $data): array
    {
        $this->notificationService->sendCostAlert([
            'type' => 'budget_exceeded',
            'current_cost' => $data['current_cost'] ?? 0,
            'budget_limit' => $data['budget_limit'] ?? 0,
            'period' => $data['period'] ?? 'unknown',
            'exceeded_by' => ($data['current_cost'] ?? 0) - ($data['budget_limit'] ?? 0),
        ]);

        return [
            'action' => 'budget_exceeded_alert',
            'notification_sent' => true,
        ];
    }

    /**
     * コストスパイク処理
     */
    private function handleCostSpike(array $data): array
    {
        $this->notificationService->sendCostAlert([
            'type' => 'cost_spike',
            'spike_amount' => $data['spike_amount'] ?? 0,
            'normal_average' => $data['normal_average'] ?? 0,
            'spike_percent' => $data['spike_percent'] ?? 0,
            'detection_time' => $data['detection_time'] ?? now()->toISOString(),
        ]);

        return [
            'action' => 'cost_spike_alert',
            'notification_sent' => true,
        ];
    }

    /**
     * 使用量異常処理
     */
    private function handleUsageAnomaly(array $data): array
    {
        $this->notificationService->sendPerformanceAlert([
            'type' => 'usage_anomaly',
            'anomaly_type' => $data['anomaly_type'] ?? 'unknown',
            'detected_value' => $data['detected_value'] ?? 0,
            'expected_range' => $data['expected_range'] ?? [],
            'detection_time' => $data['detection_time'] ?? now()->toISOString(),
        ]);

        return [
            'action' => 'usage_anomaly_alert',
            'notification_sent' => true,
        ];
    }

    /**
     * コスト閾値処理
     */
    private function handleCostThreshold(array $data): array
    {
        $this->notificationService->sendCostAlert($data);

        return [
            'action' => 'cost_threshold_alert',
            'notification_sent' => true,
        ];
    }

    /**
     * 汎用コストアラート処理
     */
    private function handleGenericCostAlert(array $data): array
    {
        $this->notificationService->sendCostAlert($data);

        return [
            'action' => 'generic_cost_alert',
            'notification_sent' => true,
        ];
    }

    /**
     * 汎用Webhook処理
     */
    private function processGenericWebhook(string $type, array $payload): array
    {
        // タイプに応じたカスタム処理を実装
        return [
            'type' => $type,
            'payload_size' => count($payload),
            'processed' => true,
        ];
    }
}