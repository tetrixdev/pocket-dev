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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider');                // 'anthropic', 'openai'
            $table->string('model_id');                // 'claude-sonnet-4-20250514'
            $table->string('display_name');            // 'Claude Sonnet 4'
            $table->integer('context_window');         // 200000
            $table->integer('max_output_tokens')->nullable(); // Max output tokens if different from default
            $table->decimal('input_price_per_million', 10, 4);
            $table->decimal('output_price_per_million', 10, 4);
            $table->boolean('is_active')->default(true);
            $table->boolean('supports_streaming')->default(true);
            $table->boolean('supports_tools')->default(true);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_extended_thinking')->default(false);
            $table->integer('sort_order')->default(0); // For display ordering
            $table->timestamps();

            $table->unique(['provider', 'model_id']);
            $table->index(['provider', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
