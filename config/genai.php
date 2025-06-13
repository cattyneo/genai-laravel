<?php

return [
    // ----- 基本設定 -----
    'defaults' => [
        'timeout' => 30, // 秒
        'async' => true, // 非同期処理

        'model' => env('GENAI_DEFAULT_MODEL', 'gpt-4.1-mini'),
        'provider' => env('GENAI_DEFAULT_PROVIDER', 'openai'),
        'options' => [
            'temperature' => 0.7,
            'top_p' => 0.95,
            'max_tokens' => 2000,
            'presence_penalty' => 0,
            'frequency_penalty' => 0,
        ],
    ],

    // ----- キャッシュ設定 -----
    'cache' => [
        'enabled' => env('GENAI_CACHE_ENABLED', true), // 修正完了後に再有効化
        'ttl' => env('GENAI_CACHE_TTL', 3600), // 秒
        'driver' => env('GENAI_CACHE_DRIVER', 'redis'), // file, redis, dynamodb
        'prefix' => 'genai_cache',
        'tags' => ['genai'], // Redisタグ付け
    ],

    // ----- パス設定 -----
    'paths' => [
        'presets'  => env('GENAI_PRESETS_PATH', 'genai/presets'),
        'prompts'  => env('GENAI_PROMPTS_PATH', 'genai/prompts'),
    ],

    // ----- プロバイダー設定
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'models_endpoint' => env('OPENAI_MODELS_ENDPOINT', 'https://api.openai.com/v1/models'),
            'headers' => [
                'Authorization' => 'Bearer {api_key}',
                'Content-Type' => 'application/json',
            ],
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'models_endpoint' => env('GEMINI_MODELS_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models'),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query_params' => [
                'key' => '{api_key}',
            ],
        ],
        'claude' => [
            'api_key' => env('CLAUDE_API_KEY'),
            'base_url' => env('CLAUDE_BASE_URL', 'https://api.anthropic.com/v1'),
            'models_endpoint' => env('CLAUDE_MODELS_ENDPOINT', 'https://api.anthropic.com/v1/models'),
            'headers' => [
                'x-api-key' => '{api_key}',
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
        ],
        'grok' => [
            'api_key' => env('GROK_API_KEY'),
            'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
            'models_endpoint' => env('GROK_MODELS_ENDPOINT', 'https://api.x.ai/v1/models'),
            'headers' => [
                'Authorization' => 'Bearer {api_key}',
                'Content-Type' => 'application/json',
            ],
        ],
    ],

    // ----- モデル設定 -----
    // モデル情報は storage/genai/models.yaml から読み込まれます
    // php artisan genai:model-list でモデル一覧を確認できます

    // ----- 価格設定 -----
    'pricing' => [
        'currency' => 'JPY', // ISO 4217
        'exchange_rate' => 150, // 1 USD = 150 JPY
        'decimal_places' => 2, // 小数部分の桁数
    ],

    // ----- リトライ設定 -----
    'retry' => [
        'max_attempts' => env('GENAI_RETRY_MAX_ATTEMPTS', 3),
        'delay' => env('GENAI_RETRY_DELAY', 1000), // ミリ秒
        'multiplier' => env('GENAI_RETRY_MULTIPLIER', 2), // 指数バックオフ
        'exceptions' => [
            'RateLimitException',
            'TimeoutException',
            'ConnectionException',
        ],
    ],

    // ----- ログ設定 -----
    'logging' => [
        'enabled' => env('GENAI_LOGGING_ENABLED', true),
        'log_requests' => env('GENAI_LOG_REQUESTS', true),
        'log_responses' => env('GENAI_LOG_RESPONSES', true),
        'log_errors' => env('GENAI_LOG_ERRORS', true),

        // データベースロガー最適化設定
        'use_optimized' => env('GENAI_LOGGING_USE_OPTIMIZED', true),
        'batch_size' => env('GENAI_LOGGING_BATCH_SIZE', 10),
        'defer_stats_update' => env('GENAI_LOGGING_DEFER_STATS', true),
    ],

    // ----- 使用統計・履歴設定 -----
    'analytics' => [
        'history_retention_days' => env('GENAI_HISTORY_RETENTION_DAYS', 30), // デフォルト30日間の履歴表示
        'cleanup_after_days' => env('GENAI_CLEANUP_AFTER_DAYS', 365), // 1年後にクリーンアップ
        'enable_detailed_analytics' => env('GENAI_DETAILED_ANALYTICS', true),
    ],

    // ----- スケジュール設定 -----
    'scheduled_tasks' => [
        'model_update_check' => [
            'enabled' => env('GENAI_AUTO_MODEL_CHECK', true),
            'frequency' => env('GENAI_MODEL_CHECK_FREQUENCY', 'daily'), // daily, weekly, monthly
            'time' => env('GENAI_MODEL_CHECK_TIME', '04:00'), // AM 4:00
            'notify_channels' => env('GENAI_MODEL_NOTIFY_CHANNELS', 'log'), // log, mail, slack
        ],
        'deprecation_check' => [
            'enabled' => env('GENAI_AUTO_DEPRECATION_CHECK', true),
            'frequency' => env('GENAI_DEPRECATION_CHECK_FREQUENCY', 'daily'),
            'time' => env('GENAI_DEPRECATION_CHECK_TIME', '04:15'), // AM 4:15
            'advance_warning_days' => env('GENAI_DEPRECATION_WARNING_DAYS', 30), // 30日前に警告
        ],
    ],

    // ----- 通知設定 -----
    'notifications' => [
        'deprecation_channels' => explode(',', env('GENAI_DEPRECATION_CHANNELS', 'log,mail')),
        'update_channels' => explode(',', env('GENAI_UPDATE_CHANNELS', 'log')),
        'cost_alert_channels' => explode(',', env('GENAI_COST_ALERT_CHANNELS', 'log,mail')),
        'performance_alert_channels' => explode(',', env('GENAI_PERF_ALERT_CHANNELS', 'log')),
        'report_channels' => explode(',', env('GENAI_REPORT_CHANNELS', 'mail')),

        // メール設定
        'mail_recipients' => explode(',', env('GENAI_MAIL_RECIPIENTS', '')),

        // Slack設定
        'slack_webhook_url' => env('GENAI_SLACK_WEBHOOK_URL'),

        // Teams設定
        'teams_webhook_url' => env('GENAI_TEAMS_WEBHOOK_URL'),

        // Webhook設定
        'webhook_urls' => explode(',', env('GENAI_WEBHOOK_URLS', '')),

        // しきい値設定
        'cost_thresholds' => [
            'warning' => env('GENAI_COST_WARNING_THRESHOLD', 10000), // ¥10,000
            'critical' => env('GENAI_COST_CRITICAL_THRESHOLD', 50000), // ¥50,000
        ],
        'performance_thresholds' => [
            'response_time_warning' => env('GENAI_RESPONSE_TIME_WARNING', 5000), // 5秒
            'response_time_critical' => env('GENAI_RESPONSE_TIME_CRITICAL', 10000), // 10秒
            'error_rate_warning' => env('GENAI_ERROR_RATE_WARNING', 5.0), // 5%
            'error_rate_critical' => env('GENAI_ERROR_RATE_CRITICAL', 10.0), // 10%
        ],
    ],

    // ----- プリセット自動更新設定 -----
    'preset_auto_update' => [
        'enabled' => env('GENAI_PRESET_AUTO_UPDATE', true),
        'rules' => [
            'enable_performance_upgrades' => env('GENAI_AUTO_PERF_UPGRADE', true),
            'enable_cost_optimization' => env('GENAI_AUTO_COST_OPT', true),
            'enable_deprecation_replacement' => env('GENAI_AUTO_DEPRECATION_REPLACE', true),
            'confidence_threshold' => env('GENAI_UPDATE_CONFIDENCE_THRESHOLD', 0.7),
            'max_cost_increase' => env('GENAI_MAX_COST_INCREASE', 1.1), // 10%まで
        ],
        'backup_retention_days' => env('GENAI_PRESET_BACKUP_DAYS', 30),
        'notification_channels' => explode(',', env('GENAI_PRESET_NOTIFY_CHANNELS', 'log,mail')),
    ],

    // ----- レート制限 -----
    'rate_limits' => [
        'default' => [
            'requests_per_minute' => 60,
            'requests_per_day' => 1000,
            'tokens_per_minute' => 90000,
        ],
        'providers' => [
            'openai' => [
                'requests_per_minute' => 500,
                'tokens_per_minute' => 90000,
            ],
            'gemini' => [
                'requests_per_minute' => 60,
                'requests_per_day' => 1500,
            ],
            'claude' => [
                'requests_per_minute' => 50,
                'tokens_per_minute' => 100000,
            ],
            'grok' => [
                'requests_per_minute' => 60,
            ],
        ],
        'models' => [
            // 'gpt-4o' => [...],
        ],
    ],

    // ----- 高度なサービス設定 -----
    'advanced_services' => [
        // 通知サービス
        'notifications' => [
            'enabled' => env('GENAI_NOTIFICATIONS_ENABLED', true),
            'channels' => [
                'email' => env('GENAI_NOTIFICATIONS_EMAIL_ENABLED', true),
                'slack' => env('GENAI_NOTIFICATIONS_SLACK_ENABLED', false),
                'discord' => env('GENAI_NOTIFICATIONS_DISCORD_ENABLED', false),
                'teams' => env('GENAI_NOTIFICATIONS_TEAMS_ENABLED', false),
            ],
            'thresholds' => [
                'cost_warning' => env('GENAI_COST_WARNING_THRESHOLD', 10000),
                'cost_critical' => env('GENAI_COST_CRITICAL_THRESHOLD', 50000),
                'response_time_warning' => env('GENAI_RESPONSE_TIME_WARNING', 5000),
                'response_time_critical' => env('GENAI_RESPONSE_TIME_CRITICAL', 10000),
                'error_rate_warning' => env('GENAI_ERROR_RATE_WARNING', 5.0),
                'error_rate_critical' => env('GENAI_ERROR_RATE_CRITICAL', 10.0),
            ],
        ],

        // プリセット自動更新サービス
        'auto_update' => [
            'enabled' => env('GENAI_AUTO_UPDATE_ENABLED', true),
            'strategy' => env('GENAI_AUTO_UPDATE_STRATEGY', 'moderate'), // conservative, moderate, aggressive
            'schedule' => env('GENAI_AUTO_UPDATE_SCHEDULE', '0 2 * * *'), // 毎日午前2時
            'backup_retention_days' => env('GENAI_BACKUP_RETENTION_DAYS', 30),
            'notification_channels' => ['email', 'log'],
            'rules' => [
                'enable_performance_upgrades' => env('GENAI_ENABLE_PERF_UPGRADES', true),
                'enable_cost_optimization' => env('GENAI_ENABLE_COST_OPT', true),
                'enable_deprecation_replacement' => env('GENAI_ENABLE_DEPRECATION_REPLACE', true),
                'confidence_threshold' => env('GENAI_UPDATE_CONFIDENCE_THRESHOLD', 0.7),
                'max_cost_increase' => env('GENAI_MAX_COST_INCREASE', 1.1),
            ],
        ],

        // パフォーマンス監視サービス
        'monitoring' => [
            'enabled' => env('GENAI_MONITORING_ENABLED', true),
            'metrics_retention_days' => env('GENAI_METRICS_RETENTION_DAYS', 90),
            'realtime_enabled' => env('GENAI_REALTIME_MONITORING', true),
            'collection_interval' => env('GENAI_COLLECTION_INTERVAL', 60), // 秒
            'alert_thresholds' => [
                'response_time_p95' => env('GENAI_RESPONSE_TIME_P95_THRESHOLD', 5000),
                'error_rate' => env('GENAI_ERROR_RATE_THRESHOLD', 5.0),
                'throughput_drop' => env('GENAI_THROUGHPUT_DROP_THRESHOLD', 20.0), // %
            ],
            'alert_cooldown' => env('GENAI_ALERT_COOLDOWN', 300), // 秒
        ],

        // コスト最適化サービス
        'cost_optimization' => [
            'enabled' => env('GENAI_COST_OPTIMIZATION_ENABLED', true),
            'auto_suggestions' => env('GENAI_AUTO_COST_SUGGESTIONS', true),
            'optimization_frequency' => env('GENAI_OPTIMIZATION_FREQUENCY', 'daily'),
            'budget_limits' => [
                'daily' => env('GENAI_DAILY_BUDGET', null),
                'weekly' => env('GENAI_WEEKLY_BUDGET', null),
                'monthly' => env('GENAI_MONTHLY_BUDGET', null),
            ],
            'savings_threshold' => env('GENAI_SAVINGS_THRESHOLD', 10.0), // %の節約で推奨
            'model_replacement_confidence' => env('GENAI_MODEL_REPLACEMENT_CONFIDENCE', 0.8),
        ],
    ],
];
