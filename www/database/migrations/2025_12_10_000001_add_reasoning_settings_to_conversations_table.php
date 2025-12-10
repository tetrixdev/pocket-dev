<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Provider-specific reasoning settings:
     * - Anthropic uses budget_tokens (explicit token allocation for thinking)
     * - OpenAI uses effort (none/low/medium/high) + summary (concise/detailed/auto/null)
     *
     * These are stored per-conversation to persist settings when switching between conversations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Anthropic-specific: explicit token budget for thinking
            $table->unsignedInteger('anthropic_thinking_budget')->nullable()->after('model');

            // OpenAI-specific: effort level (none/low/medium/high)
            $table->string('openai_reasoning_effort', 20)->nullable()->after('anthropic_thinking_budget');

            // OpenAI-specific: summary display mode (concise/detailed/auto/null)
            // null means don't show thinking to user
            $table->string('openai_reasoning_summary', 20)->nullable()->after('openai_reasoning_effort');

            // Response length level (shared across providers)
            $table->unsignedSmallInteger('response_level')->default(1)->after('openai_reasoning_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'anthropic_thinking_budget',
                'openai_reasoning_effort',
                'openai_reasoning_summary',
                'response_level',
            ]);
        });
    }
};
