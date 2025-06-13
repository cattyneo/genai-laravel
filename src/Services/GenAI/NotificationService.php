<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Services\GenAI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * GenAI通知サービス
 *
 * モデル廃止警告、システムアラート、レポート配信等の通知機能を提供
 */
class NotificationService
{
    public function __construct(
        private array $config = []
    ) {
        $this->config = config('genai.notifications', []);
    }

    /**
     * モデル廃止警告を送信
     */
    public function sendDeprecationWarning(array $deprecatedModels, array $replacementSuggestions = []): bool
    {
        $channels = $this->config['deprecation_channels'] ?? ['log'];

        $notificationData = [
            'type' => 'model_deprecation_warning',
            'severity' => 'high',
            'timestamp' => now()->toISOString(),
            'deprecated_models' => $deprecatedModels,
            'replacement_suggestions' => $replacementSuggestions,
            'action_required' => true,
            'deadline' => $this->calculateDeprecationDeadline($deprecatedModels),
        ];

        $this->sendToChannels($channels, $notificationData);
        
        return true;
    }

    /**
     * モデル更新通知を送信
     */
    public function sendModelUpdateNotification(array $newModels, array $updatedModels): bool
    {
        $channels = $this->config['update_channels'] ?? ['log'];

        $notificationData = [
            'type' => 'model_update',
            'severity' => 'info',
            'timestamp' => now()->toISOString(),
            'new_models' => $newModels,
            'updated_models' => $updatedModels,
            'total_new' => count($newModels),
            'total_updated' => count($updatedModels),
        ];

        $this->sendToChannels($channels, $notificationData);
        
        return true;
    }

    /**
     * コスト警告通知を送信
     */
    public function sendCostAlert(array $costData): bool
    {
        $channels = $this->config['cost_alert_channels'] ?? ['log', 'mail'];

        $notificationData = [
            'type' => 'cost_alert',
            'severity' => $this->determineCostSeverity($costData),
            'timestamp' => now()->toISOString(),
            'cost_data' => $costData,
            'threshold_exceeded' => $costData['threshold_exceeded'] ?? false,
            'recommended_actions' => $this->generateCostRecommendations($costData),
        ];

        $this->sendToChannels($channels, $notificationData);
        
        return true;
    }

    /**
     * パフォーマンス劣化アラートを送信
     */
    public function sendPerformanceAlert(array $performanceData): bool
    {
        $channels = $this->config['performance_alert_channels'] ?? ['log'];

        $notificationData = [
            'type' => 'performance_alert',
            'severity' => $this->determinePerformanceSeverity($performanceData),
            'timestamp' => now()->toISOString(),
            'performance_data' => $performanceData,
            'affected_models' => $performanceData['affected_models'] ?? [],
            'recommended_actions' => $this->generatePerformanceRecommendations($performanceData),
        ];

        $this->sendToChannels($channels, $notificationData);
        
        return true;
    }

    /**
     * 定期レポートを送信
     */
    public function sendScheduledReport(string $reportType, array $reportData): void
    {
        $channels = $this->config['report_channels'] ?? ['mail'];

        $notificationData = [
            'type' => 'scheduled_report',
            'report_type' => $reportType,
            'severity' => 'info',
            'timestamp' => now()->toISOString(),
            'report_data' => $reportData,
            'period' => $reportData['period'] ?? 'unknown',
        ];

        $this->sendToChannels($channels, $notificationData);
    }

    /**
     * 複数チャンネルに通知を送信
     */
    private function sendToChannels(array $channels, array $notificationData): void
    {
        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'log' => $this->sendLogNotification($notificationData),
                    'mail' => $this->sendMailNotification($notificationData),
                    'slack' => $this->sendSlackNotification($notificationData),
                    'teams' => $this->sendTeamsNotification($notificationData),
                    'webhook' => $this->sendWebhookNotification($notificationData),
                    'database' => $this->sendDatabaseNotification($notificationData),
                    default => Log::warning("Unknown notification channel: {$channel}")
                };
            } catch (\Exception $e) {
                Log::error("Failed to send notification via {$channel}", [
                    'error' => $e->getMessage(),
                    'notification_data' => $notificationData,
                ]);
            }
        }
    }

    /**
     * ログ通知
     */
    private function sendLogNotification(array $data): void
    {
        $level = match ($data['severity']) {
            'critical' => 'critical',
            'high' => 'error',
            'medium' => 'warning',
            'low', 'info' => 'info',
            default => 'info'
        };

        Log::$level('[GenAI] '.$data['type'], $data);
    }

    /**
     * メール通知
     */
    private function sendMailNotification(array $data): void
    {
        $recipients = $this->config['mail_recipients'] ?? [config('mail.from.address')];
        $subject = $this->generateEmailSubject($data);
        $content = $this->generateEmailContent($data);

        foreach ($recipients as $recipient) {
            try {
                Mail::raw($content, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)
                        ->subject($subject);
                });
            } catch (\Exception $e) {
                Log::error("Failed to send email to {$recipient}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Slack通知
     */
    private function sendSlackNotification(array $data): void
    {
        $webhookUrl = $this->config['slack_webhook_url'] ?? null;

        if (! $webhookUrl) {
            Log::warning('Slack webhook URL not configured');

            return;
        }

        $payload = [
            'text' => $this->generateSlackMessage($data),
            'username' => 'GenAI Bot',
            'icon_emoji' => $this->getSlackEmoji($data['type']),
            'attachments' => $this->generateSlackAttachments($data),
        ];

        Http::post($webhookUrl, $payload);
    }

    /**
     * Microsoft Teams通知
     */
    private function sendTeamsNotification(array $data): void
    {
        $webhookUrl = $this->config['teams_webhook_url'] ?? null;

        if (! $webhookUrl) {
            Log::warning('Teams webhook URL not configured');

            return;
        }

        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => $this->getTeamsColor($data['severity']),
            'summary' => $this->generateTeamsTitle($data),
            'sections' => $this->generateTeamsSections($data),
        ];

        Http::post($webhookUrl, $payload);
    }

    /**
     * Webhook通知
     */
    private function sendWebhookNotification(array $data): void
    {
        $webhookUrls = $this->config['webhook_urls'] ?? [];

        foreach ($webhookUrls as $url) {
            Http::post($url, [
                'service' => 'genai',
                'notification' => $data,
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * データベース通知（通知ログとして保存）
     */
    private function sendDatabaseNotification(array $data): void
    {
        // 通知ログテーブルがある場合の実装
        // 簡略化のため、一般的なlogテーブルへの保存として実装
        Log::channel('database')->info('[GenAI Notification]', $data);
    }

    /**
     * 廃止期限計算
     */
    private function calculateDeprecationDeadline(array $deprecatedModels): ?string
    {
        $warningDays = config('genai.scheduled_tasks.deprecation_check.advance_warning_days', 30);

        return now()->addDays($warningDays)->toDateString();
    }

    /**
     * コスト深刻度判定
     */
    private function determineCostSeverity(array $costData): string
    {
        $exceedPercent = $costData['budget_exceed_percent'] ?? 0;

        return match (true) {
            $exceedPercent >= 150 => 'critical',
            $exceedPercent >= 120 => 'high',
            $exceedPercent >= 100 => 'medium',
            default => 'low'
        };
    }

    /**
     * パフォーマンス深刻度判定
     */
    private function determinePerformanceSeverity(array $performanceData): string
    {
        $degradationPercent = $performanceData['performance_degradation_percent'] ?? 0;

        return match (true) {
            $degradationPercent >= 50 => 'critical',
            $degradationPercent >= 30 => 'high',
            $degradationPercent >= 15 => 'medium',
            default => 'low'
        };
    }

    /**
     * コスト推奨事項生成
     */
    private function generateCostRecommendations(array $costData): array
    {
        $recommendations = [];

        if (($costData['budget_exceed_percent'] ?? 0) > 100) {
            $recommendations[] = 'Consider switching to more cost-effective models';
            $recommendations[] = 'Review and optimize high-usage applications';
            $recommendations[] = 'Implement request caching to reduce API calls';
        }

        return $recommendations;
    }

    /**
     * パフォーマンス推奨事項生成
     */
    private function generatePerformanceRecommendations(array $performanceData): array
    {
        $recommendations = [];

        if (($performanceData['avg_response_time'] ?? 0) > 5000) {
            $recommendations[] = 'Consider using faster model variants';
            $recommendations[] = 'Implement parallel processing for batch requests';
            $recommendations[] = 'Review prompt complexity and length';
        }

        return $recommendations;
    }

    /**
     * メール件名生成
     */
    private function generateEmailSubject(array $data): string
    {
        $urgency = match ($data['severity']) {
            'critical' => '[URGENT] ',
            'high' => '[IMPORTANT] ',
            default => ''
        };

        $subject = match ($data['type']) {
            'model_deprecation_warning' => 'Model Deprecation Warning',
            'model_update' => 'Model Update Notification',
            'cost_alert' => 'Cost Alert',
            'performance_alert' => 'Performance Alert',
            'scheduled_report' => 'Scheduled Report',
            default => 'GenAI Notification'
        };

        return $urgency.'[GenAI] '.$subject;
    }

    /**
     * メール内容生成
     */
    private function generateEmailContent(array $data): string
    {
        $content = "GenAI Notification\n";
        $content .= "==================\n\n";
        $content .= 'Type: '.$data['type']."\n";
        $content .= 'Severity: '.strtoupper($data['severity'])."\n";
        $content .= 'Timestamp: '.$data['timestamp']."\n\n";

        switch ($data['type']) {
            case 'model_deprecation_warning':
                $content .= "DEPRECATED MODELS DETECTED:\n";
                foreach ($data['deprecated_models'] as $model) {
                    $content .= "- {$model['provider']}/{$model['model']} (Usage: {$model['usage_count']})\n";
                }
                $content .= "\nREPLACEMENT SUGGESTIONS:\n";
                foreach ($data['replacement_suggestions'] as $suggestion) {
                    $content .= "- {$suggestion['deprecated_model']} → {$suggestion['suggestions'][0]['model']['model']}\n";
                }
                break;

            case 'model_update':
                $content .= 'NEW MODELS: '.$data['total_new']."\n";
                $content .= 'UPDATED MODELS: '.$data['total_updated']."\n";
                break;

            case 'cost_alert':
                $content .= "COST OVERVIEW:\n";
                $content .= 'Current Spend: ¥'.number_format($data['cost_data']['current_spend'] ?? 0, 2)."\n";
                $content .= 'Budget Threshold: ¥'.number_format($data['cost_data']['budget_threshold'] ?? 0, 2)."\n";
                break;
        }

        return $content;
    }

    /**
     * Slackメッセージ生成
     */
    private function generateSlackMessage(array $data): string
    {
        $emoji = $this->getSlackEmoji($data['type']);

        return "{$emoji} *GenAI {$data['type']}* - Severity: {$data['severity']}";
    }

    /**
     * Slack絵文字取得
     */
    private function getSlackEmoji(string $type): string
    {
        return match ($type) {
            'model_deprecation_warning' => ':warning:',
            'model_update' => ':information_source:',
            'cost_alert' => ':money_with_wings:',
            'performance_alert' => ':chart_with_downwards_trend:',
            'scheduled_report' => ':bar_chart:',
            default => ':robot_face:'
        };
    }

    /**
     * Slack添付ファイル生成
     */
    private function generateSlackAttachments(array $data): array
    {
        return [
            [
                'color' => $this->getSlackColor($data['severity']),
                'fields' => $this->generateSlackFields($data),
                'ts' => now()->timestamp,
            ],
        ];
    }

    /**
     * Slack色取得
     */
    private function getSlackColor(string $severity): string
    {
        return match ($severity) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => '#ff9900',
            'low', 'info' => 'good',
            default => '#36a64f'
        };
    }

    /**
     * Slackフィールド生成
     */
    private function generateSlackFields(array $data): array
    {
        $fields = [
            [
                'title' => 'Type',
                'value' => $data['type'],
                'short' => true,
            ],
            [
                'title' => 'Severity',
                'value' => strtoupper($data['severity']),
                'short' => true,
            ],
        ];

        if (isset($data['deprecated_models'])) {
            $fields[] = [
                'title' => 'Deprecated Models',
                'value' => count($data['deprecated_models']).' models require attention',
                'short' => false,
            ];
        }

        return $fields;
    }

    /**
     * Teams色取得
     */
    private function getTeamsColor(string $severity): string
    {
        return match ($severity) {
            'critical' => 'FF0000',
            'high' => 'FF9900',
            'medium' => 'FFCC00',
            'low', 'info' => '00AA00',
            default => '0078D4'
        };
    }

    /**
     * Teamsタイトル生成
     */
    private function generateTeamsTitle(array $data): string
    {
        return 'GenAI '.ucwords(str_replace('_', ' ', $data['type']));
    }

    /**
     * Teamsセクション生成
     */
    private function generateTeamsSections(array $data): array
    {
        return [
            [
                'activityTitle' => $this->generateTeamsTitle($data),
                'activitySubtitle' => 'Severity: '.strtoupper($data['severity']),
                'facts' => $this->generateTeamsFacts($data),
            ],
        ];
    }

    /**
     * Teams事実情報生成
     */
    private function generateTeamsFacts(array $data): array
    {
        $facts = [
            ['name' => 'Type', 'value' => $data['type']],
            ['name' => 'Timestamp', 'value' => $data['timestamp']],
        ];

        if (isset($data['deprecated_models'])) {
            $facts[] = ['name' => 'Affected Models', 'value' => count($data['deprecated_models'])];
        }

        return $facts;
    }

    /**
     * プリセット更新通知送信
     */
    public function sendPresetUpdateNotification(array $updateData): bool
    {
        try {
            $config = config('genai.notifications', []);

            if (! ($config['enabled'] ?? false)) {
                return false;
            }

            $channels = $config['channels'] ?? [];
            $sent = false;

            // メール通知
            if (in_array('mail', $channels) && ! empty($config['mail']['to'])) {
                $this->sendPresetUpdateEmail($updateData, $config['mail']);
                $sent = true;
            }

            // Slack通知
            if (in_array('slack', $channels) && ! empty($config['slack']['webhook_url'])) {
                $this->sendPresetUpdateSlack($updateData, $config['slack']);
                $sent = true;
            }

            // Teams通知
            if (in_array('teams', $channels) && ! empty($config['teams']['webhook_url'])) {
                $this->sendPresetUpdateTeams($updateData, $config['teams']);
                $sent = true;
            }

            return $sent;
        } catch (\Exception $e) {
            Log::error('Failed to send preset update notification', [
                'error' => $e->getMessage(),
                'data' => $updateData,
            ]);

            return false;
        }
    }

    /**
     * プリセット更新メール送信
     */
    private function sendPresetUpdateEmail(array $data, array $config): void
    {
        $subject = 'GenAI Preset Update Notification';
        $message = $this->formatPresetUpdateMessage($data);

        Mail::raw($message, function ($mail) use ($config, $subject) {
            $mail->to($config['to'])
                ->subject($subject);
        });
    }

    /**
     * プリセット更新Slack送信
     */
    private function sendPresetUpdateSlack(array $data, array $config): void
    {
        $payload = [
            'text' => 'GenAI Preset Update',
            'attachments' => [
                [
                    'color' => 'good',
                    'title' => 'Preset Update Notification',
                    'text' => $this->formatPresetUpdateMessage($data),
                    'ts' => now()->timestamp,
                ],
            ],
        ];

        Http::post($config['webhook_url'], $payload);
    }

    /**
     * プリセット更新Teams送信
     */
    private function sendPresetUpdateTeams(array $data, array $config): void
    {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => 'GenAI Preset Update',
            'themeColor' => '0078D4',
            'title' => 'Preset Update Notification',
            'text' => $this->formatPresetUpdateMessage($data),
        ];

        Http::post($config['webhook_url'], $payload);
    }

    /**
     * プリセット更新メッセージフォーマット
     */
    private function formatPresetUpdateMessage(array $data): string
    {
        $message = "Preset Update Summary:\n\n";

        if (isset($data['updated_presets'])) {
            $message .= 'Updated Presets: '.count($data['updated_presets'])."\n";
            foreach ($data['updated_presets'] as $preset) {
                $message .= "- {$preset}\n";
            }
        }

        if (isset($data['suggestions'])) {
            $message .= "\nSuggestions: ".count($data['suggestions'])."\n";
        }

        if (isset($data['errors']) && ! empty($data['errors'])) {
            $message .= "\nErrors: ".count($data['errors'])."\n";
        }

        $message .= "\nTimestamp: ".now()->toDateTimeString();

        return $message;
    }
}
