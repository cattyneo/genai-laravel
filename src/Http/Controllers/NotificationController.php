<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use CattyNeo\LaravelGenAI\Services\GenAI\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GenAI 通知管理 API コントローラー
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {
    }

    /**
     * 通知履歴取得
     */
    public function getHistory(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 50);
            $type = $request->get('type'); // cost_alert, performance_alert, deprecation_warning, etc.
            $severity = $request->get('severity'); // critical, warning, info
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $history = $this->notificationService->getNotificationHistory([
                'limit' => $limit,
                'type' => $type,
                'severity' => $severity,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $history,
                    'filters' => [
                        'type' => $type,
                        'severity' => $severity,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'limit' => $limit,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'count' => count($history),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get notification history',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * アクティブアラート取得
     */
    public function getActiveAlerts(Request $request): JsonResponse
    {
        try {
            $severity = $request->get('severity');
            $category = $request->get('category'); // cost, performance, system, deprecation

            $alerts = $this->notificationService->getActiveAlerts($severity, $category);

            return response()->json([
                'success' => true,
                'data' => [
                    'active_alerts' => $alerts,
                    'summary' => [
                        'total' => count($alerts),
                        'critical' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'critical')),
                        'warning' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'warning')),
                        'info' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'info')),
                    ],
                    'filters' => [
                        'severity' => $severity,
                        'category' => $category,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get active alerts',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * アラート確認
     */
    public function acknowledgeAlert(Request $request, string $alert): JsonResponse
    {
        try {
            $acknowledged = $this->notificationService->acknowledgeAlert(
                $alert,
                $request->get('acknowledged_by', 'API'),
                $request->get('notes')
            );

            if ($acknowledged) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'alert_id' => $alert,
                        'acknowledged_at' => now()->toISOString(),
                        'acknowledged_by' => $request->get('acknowledged_by', 'API'),
                        'message' => 'Alert acknowledged successfully',
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Alert not found or already acknowledged',
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to acknowledge alert',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 通知設定取得
     */
    public function getSettings(Request $request): JsonResponse
    {
        try {
            $settings = $this->notificationService->getNotificationSettings();

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'available_channels' => ['email', 'slack', 'discord', 'teams'],
                    'available_severities' => ['critical', 'warning', 'info'],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get notification settings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 通知設定更新
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $settings = $request->validate([
                'channels' => 'sometimes|array',
                'channels.email' => 'sometimes|boolean',
                'channels.slack' => 'sometimes|boolean',
                'channels.discord' => 'sometimes|boolean',
                'channels.teams' => 'sometimes|boolean',
                'thresholds' => 'sometimes|array',
                'thresholds.cost_warning' => 'sometimes|numeric|min:0',
                'thresholds.cost_critical' => 'sometimes|numeric|min:0',
                'thresholds.response_time_warning' => 'sometimes|integer|min:1000',
                'thresholds.response_time_critical' => 'sometimes|integer|min:1000',
                'thresholds.error_rate_warning' => 'sometimes|numeric|min:0|max:100',
                'thresholds.error_rate_critical' => 'sometimes|numeric|min:0|max:100',
                'recipients' => 'sometimes|array',
                'recipients.email' => 'sometimes|array',
                'recipients.slack_webhook' => 'sometimes|string|url',
                'recipients.discord_webhook' => 'sometimes|string|url',
                'recipients.teams_webhook' => 'sometimes|string|url',
            ]);

            $updated = $this->notificationService->updateNotificationSettings($settings);

            return response()->json([
                'success' => true,
                'data' => [
                    'updated_settings' => $updated,
                    'message' => 'Notification settings updated successfully',
                ],
                'meta' => [
                    'updated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update notification settings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * テスト通知送信
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        try {
            $channel = $request->validate([
                'channel' => 'required|in:email,slack,discord,teams',
                'message' => 'sometimes|string|max:500',
            ]);

            $testMessage = $channel['message'] ?? 'This is a test notification from GenAI system.';

            $sent = $this->notificationService->sendTestNotification(
                $channel['channel'],
                $testMessage
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'sent' => $sent,
                    'channel' => $channel['channel'],
                    'message' => $testMessage,
                    'sent_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send test notification',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 通知統計取得
     */
    public function getNotificationStats(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', 'last_30_days');
            $groupBy = $request->get('group_by', 'day');

            [$startDate, $endDate] = $this->getPeriodDates($period);

            $stats = $this->notificationService->getNotificationStats(
                $startDate,
                $endDate,
                $groupBy
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'group_by' => $groupBy,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get notification stats',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 通知チャネル状態確認
     */
    public function checkChannelStatus(Request $request): JsonResponse
    {
        try {
            $status = $this->notificationService->checkChannelStatus();

            return response()->json([
                'success' => true,
                'data' => [
                    'channel_status' => $status,
                    'overall_health' => $this->calculateOverallHealth($status),
                ],
                'meta' => [
                    'checked_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to check channel status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 期間から開始日・終了日を取得
     */
    private function getPeriodDates(string $period): array
    {
        $now = now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(7), $now],
            'last_30_days' => [$now->copy()->subDays(30), $now],
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            default => [$now->copy()->subDays(30), $now],
        };
    }

    /**
     * 全体的な健康状態を計算
     */
    private function calculateOverallHealth(array $channelStatus): string
    {
        $healthyChannels = count(array_filter($channelStatus, fn ($status) => $status['status'] === 'healthy'));
        $totalChannels = count($channelStatus);

        if ($totalChannels === 0) {
            return 'unknown';
        }

        $healthPercent = ($healthyChannels / $totalChannels) * 100;

        if ($healthPercent === 100) {
            return 'excellent';
        } elseif ($healthPercent >= 75) {
            return 'good';
        } elseif ($healthPercent >= 50) {
            return 'fair';
        } else {
            return 'poor';
        }
    }
}
