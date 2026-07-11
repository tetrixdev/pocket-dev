<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add context window tracking columns to conversations table.
     *
     * - last_context_tokens: The total input tokens from the most recent assistant response.
     *   For Claude Code: input_tokens + cache_creation + cache_read = full context size.
     *   This represents how much of the context window is being used.
     *
     * - context_window_size: The context window limit for the current model.
     *   Cached to avoid repeated lookups and for historical accuracy when model changes.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Most recent context size (from last assistant response input_tokens)
            // This represents the actual tokens sent in the last request
            $table->unsignedInteger('last_context_tokens')->nullable()->after('total_output_tokens');

            // Context window size for current model (cached for quick access)
            // Updated when model changes, used for percentage calculations
            $table->unsignedInteger('context_window_size')->nullable()->after('last_context_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_context_tokens', 'context_window_size']);
        });
    }
};
