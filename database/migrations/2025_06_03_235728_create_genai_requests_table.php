<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('genai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50); // openai, gemini, claude, grok
            $table->string('model', 100);
            $table->text('prompt');
            $table->text('system_prompt')->nullable();
            $table->json('options')->nullable();
            $table->json('vars')->nullable();
            $table->text('response');
            $table->json('response_usage')->nullable();
            $table->decimal('cost', 10, 6)->default(0);
            $table->json('response_meta')->nullable();
            $table->string('status', 20)->default('success'); // success, error
            $table->text('error_message')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('duration_ms', 10, 2)->nullable();
            $table->string('user_id', 50)->nullable()->index();
            $table->string('session_id', 100)->nullable();
            $table->string('request_id', 100)->nullable()->index();

            // ----- 使用統計分析用カラム追加 -----
            $table->string('use_case', 100)->nullable()->index(); // chat, translation, coding, content_generation
            $table->string('preset_name', 100)->nullable()->index(); // 使用されたプリセット名
            $table->boolean('is_cached')->default(false)->index(); // キャッシュヒットフラグ
            $table->string('response_quality', 20)->nullable(); // excellent, good, fair, poor
            $table->tinyInteger('user_rating')->nullable(); // 1-5の評価
            $table->json('performance_metrics')->nullable(); // 応答時間、品質スコア等
            $table->string('model_version', 50)->nullable(); // モデルバージョン追跡
            $table->boolean('is_deprecated_model')->default(false)->index(); // 廃止予定モデル
            $table->string('replacement_suggestion', 100)->nullable(); // 代替モデル提案
            $table->string('context_type', 50)->nullable(); // web, api, cli, background

            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['status', 'created_at']);
            // ----- 分析用インデックス追加 -----
            $table->index(['use_case', 'created_at']);
            $table->index(['is_cached', 'created_at']);
            $table->index(['is_deprecated_model', 'created_at']);
            $table->index(['cost', 'created_at']);
            $table->index(['user_rating', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genai_requests');
    }
};
