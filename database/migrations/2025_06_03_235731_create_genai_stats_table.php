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
        Schema::create('genai_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->integer('total_requests')->default(0);
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->integer('total_input_tokens')->default(0);
            $table->integer('total_output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost', 10, 6)->default(0);
            $table->decimal('avg_duration_ms', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'provider', 'model']);
            $table->index(['date', 'provider']);
            $table->index(['provider', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genai_stats');
    }
};
