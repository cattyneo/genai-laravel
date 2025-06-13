<?php

use CattyNeo\LaravelGenAI\Http\Controllers\ModelRecommendationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GenAI API Routes
|--------------------------------------------------------------------------
|
| GenAI パッケージのAPI エンドポイント定義
|
*/

Route::prefix('api/genai')->middleware(['api'])->group(function () {

    // モデル推奨・最適化 API
    Route::controller(ModelRecommendationController::class)->group(function () {

        // 一般的なモデル推奨
        Route::get('/recommendations', 'getRecommendations')
            ->name('genai.api.recommendations');

        // 廃止予定モデルの代替提案
        Route::get('/recommendations/deprecated', 'getDeprecatedReplacements')
            ->name('genai.api.recommendations.deprecated');

        // モデル比較
        Route::post('/recommendations/compare', 'compareModels')
            ->name('genai.api.recommendations.compare');

        // 最適化推奨事項
        Route::get('/recommendations/optimization', 'getOptimizationRecommendations')
            ->name('genai.api.recommendations.optimization');

        // 特定モデルの代替提案
        Route::get('/recommendations/{provider}/{model}', 'getModelReplacements')
            ->name('genai.api.recommendations.model');
    });

    // パフォーマンス監視 API
    Route::prefix('performance')->group(function () {

        // リアルタイムメトリクス
        Route::get('/metrics/realtime', 'PerformanceController@getRealTimeMetrics')
            ->name('genai.api.performance.realtime');

        // パフォーマンストレンド
        Route::get('/trends', 'PerformanceController@getPerformanceTrends')
            ->name('genai.api.performance.trends');

        // パフォーマンス履歴
        Route::get('/history/{days?}', 'PerformanceController@getPerformanceHistory')
            ->name('genai.api.performance.history');
    });

    // コスト分析 API
    Route::prefix('cost')->group(function () {

        // 月次レポート
        Route::get('/reports/monthly/{month?}', 'CostController@getMonthlyReport')
            ->name('genai.api.cost.monthly');

        // 週次レポート
        Route::get('/reports/weekly/{week?}', 'CostController@getWeeklyReport')
            ->name('genai.api.cost.weekly');

        // コスト概要
        Route::get('/summary', 'CostController@getCostSummary')
            ->name('genai.api.cost.summary');

        // 最適化機会
        Route::get('/optimization-opportunities', 'CostController@getOptimizationOpportunities')
            ->name('genai.api.cost.optimization');
    });

    // プリセット管理 API
    Route::prefix('presets')->group(function () {

        // プリセット一覧
        Route::get('/', 'PresetController@index')
            ->name('genai.api.presets.index');

        // プリセット詳細
        Route::get('/{preset}', 'PresetController@show')
            ->name('genai.api.presets.show');

        // プリセット作成
        Route::post('/', 'PresetController@store')
            ->name('genai.api.presets.store');

        // プリセット更新
        Route::put('/{preset}', 'PresetController@update')
            ->name('genai.api.presets.update');

        // プリセット削除
        Route::delete('/{preset}', 'PresetController@destroy')
            ->name('genai.api.presets.destroy');

        // プリセット自動更新状況
        Route::get('/{preset}/auto-update-status', 'PresetController@getAutoUpdateStatus')
            ->name('genai.api.presets.auto-update-status');
    });

    // 分析・統計 API
    Route::prefix('analytics')->group(function () {

        // 使用統計
        Route::get('/usage/{period?}', 'AnalyticsController@getUsageStats')
            ->name('genai.api.analytics.usage');

        // プロバイダー比較
        Route::get('/providers/comparison', 'AnalyticsController@getProviderComparison')
            ->name('genai.api.analytics.providers');

        // モデル使用率
        Route::get('/models/usage', 'AnalyticsController@getModelUsage')
            ->name('genai.api.analytics.models');

        // 品質メトリクス
        Route::get('/quality/{period?}', 'AnalyticsController@getQualityMetrics')
            ->name('genai.api.analytics.quality');
    });

    // システム管理 API
    Route::prefix('system')->middleware(['auth:api'])->group(function () {

        // システム状態
        Route::get('/status', 'SystemController@getSystemStatus')
            ->name('genai.api.system.status');

        // ヘルスチェック
        Route::get('/health', 'SystemController@healthCheck')
            ->name('genai.api.system.health');

        // 設定情報
        Route::get('/config', 'SystemController@getConfig')
            ->name('genai.api.system.config');

        // キャッシュ管理
        Route::delete('/cache', 'SystemController@clearCache')
            ->name('genai.api.system.cache.clear');

        // 手動モデル更新
        Route::post('/models/update', 'SystemController@updateModels')
            ->name('genai.api.system.models.update');
    });

    // 通知・アラート API
    Route::prefix('notifications')->middleware(['auth:api'])->group(function () {

        // 通知履歴
        Route::get('/history', 'NotificationController@getHistory')
            ->name('genai.api.notifications.history');

        // アクティブアラート
        Route::get('/alerts/active', 'NotificationController@getActiveAlerts')
            ->name('genai.api.notifications.alerts.active');

        // アラート確認
        Route::put('/alerts/{alert}/acknowledge', 'NotificationController@acknowledgeAlert')
            ->name('genai.api.notifications.alerts.acknowledge');

        // 通知設定
        Route::get('/settings', 'NotificationController@getSettings')
            ->name('genai.api.notifications.settings');

        Route::put('/settings', 'NotificationController@updateSettings')
            ->name('genai.api.notifications.settings.update');
    });
});

// Webhook エンドポイント
Route::post('/genai/webhooks/model-updates', 'WebhookController@handleModelUpdate')
    ->name('genai.webhooks.model-update');

Route::post('/genai/webhooks/cost-alerts', 'WebhookController@handleCostAlert')
    ->name('genai.webhooks.cost-alert');
