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
        Schema::table('conversations', function (Blueprint $table) {
            // Claude Code session ID for conversation continuity
            $table->string('claude_session_id')->nullable()->after('openai_reasoning_effort');

            // Claude Code thinking tokens (similar to anthropic_thinking_budget)
            $table->integer('claude_code_thinking_tokens')->nullable()->after('openai_reasoning_effort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['claude_session_id', 'claude_code_thinking_tokens']);
        });
    }
};
