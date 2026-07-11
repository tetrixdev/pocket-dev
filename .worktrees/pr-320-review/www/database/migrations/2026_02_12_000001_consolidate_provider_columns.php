<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // CONVERSATIONS TABLE
        // =====================================================================

        // Step 1: Add new unified columns
        Schema::table('conversations', function (Blueprint $table) {
            // Unified reasoning config (JSON) - replaces all per-provider columns
            $table->json('reasoning_config')->nullable()->after('response_level');

            // Unified session ID - replaces claude_session_id and codex_session_id
            $table->string('provider_session_id', 255)->nullable()->after('reasoning_config');
        });

        // Step 2: Migrate existing data to new columns
        // Conversations: reasoning config
        DB::table('conversations')->where('provider_type', 'anthropic')
            ->whereNotNull('anthropic_thinking_budget')
            ->where('anthropic_thinking_budget', '>', 0)
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'budget_tokens' => $row->anthropic_thinking_budget,
                    ]),
                ]);
            });

        DB::table('conversations')->where('provider_type', 'openai')
            ->whereNotNull('openai_reasoning_effort')
            ->where('openai_reasoning_effort', '!=', 'none')
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'effort' => $row->openai_reasoning_effort,
                    ]),
                ]);
            });

        DB::table('conversations')->where('provider_type', 'openai_compatible')
            ->whereNotNull('openai_compatible_reasoning_effort')
            ->where('openai_compatible_reasoning_effort', '!=', 'none')
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'effort' => $row->openai_compatible_reasoning_effort,
                    ]),
                ]);
            });

        DB::table('conversations')->where('provider_type', 'claude_code')
            ->whereNotNull('claude_code_thinking_tokens')
            ->where('claude_code_thinking_tokens', '>', 0)
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'thinking_tokens' => $row->claude_code_thinking_tokens,
                    ]),
                ]);
            });

        // Note: codex_reasoning_effort has no DB column yet (latent bug).
        // It exists in $fillable but the column was never created.
        // The new reasoning_config column absorbs it going forward.

        // Conversations: session IDs
        // Scope by provider_type to avoid overwriting wrong provider's session
        // in case both columns are somehow populated (shouldn't happen, but safe)
        DB::table('conversations')
            ->where('provider_type', 'claude_code')
            ->whereNotNull('claude_session_id')
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'provider_session_id' => $row->claude_session_id,
                ]);
            });

        DB::table('conversations')
            ->where('provider_type', 'codex')
            ->whereNotNull('codex_session_id')
            ->eachById(function ($row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'provider_session_id' => $row->codex_session_id,
                ]);
            });

        // Step 3: Drop old columns from conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'anthropic_thinking_budget',
                'openai_reasoning_effort',
                'openai_compatible_reasoning_effort',
                'claude_code_thinking_tokens',
                // Note: codex_reasoning_effort column doesn't exist, so don't drop it
                'claude_session_id',
                'codex_session_id',
            ]);
        });

        // =====================================================================
        // AGENTS TABLE
        // =====================================================================

        // Step 1: Add new unified column
        Schema::table('agents', function (Blueprint $table) {
            $table->json('reasoning_config')->nullable()->after('response_level');
        });

        // Step 2: Migrate existing data
        DB::table('agents')->where('provider', 'anthropic')
            ->whereNotNull('anthropic_thinking_budget')
            ->where('anthropic_thinking_budget', '>', 0)
            ->eachById(function ($row) {
                DB::table('agents')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'budget_tokens' => $row->anthropic_thinking_budget,
                    ]),
                ]);
            });

        DB::table('agents')->where('provider', 'openai')
            ->whereNotNull('openai_reasoning_effort')
            ->where('openai_reasoning_effort', '!=', 'none')
            ->eachById(function ($row) {
                DB::table('agents')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'effort' => $row->openai_reasoning_effort,
                    ]),
                ]);
            });

        DB::table('agents')->where('provider', 'openai_compatible')
            ->whereNotNull('openai_compatible_reasoning_effort')
            ->where('openai_compatible_reasoning_effort', '!=', 'none')
            ->eachById(function ($row) {
                DB::table('agents')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'effort' => $row->openai_compatible_reasoning_effort,
                    ]),
                ]);
            });

        DB::table('agents')->where('provider', 'claude_code')
            ->whereNotNull('claude_code_thinking_tokens')
            ->where('claude_code_thinking_tokens', '>', 0)
            ->eachById(function ($row) {
                DB::table('agents')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'thinking_tokens' => $row->claude_code_thinking_tokens,
                    ]),
                ]);
            });

        DB::table('agents')->where('provider', 'codex')
            ->whereNotNull('codex_reasoning_effort')
            ->where('codex_reasoning_effort', '!=', 'none')
            ->where('codex_reasoning_effort', '!=', 'minimal')
            ->eachById(function ($row) {
                DB::table('agents')->where('id', $row->id)->update([
                    'reasoning_config' => json_encode([
                        'effort' => $row->codex_reasoning_effort,
                    ]),
                ]);
            });

        // Step 3: Drop old columns from agents
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'anthropic_thinking_budget',
                'openai_reasoning_effort',
                'openai_compatible_reasoning_effort',
                'claude_code_thinking_tokens',
                'codex_reasoning_effort',
            ]);
        });
    }

    public function down(): void
    {
        // Conversations: restore old columns
        Schema::table('conversations', function (Blueprint $table) {
            $table->integer('anthropic_thinking_budget')->nullable();
            $table->string('openai_reasoning_effort', 20)->nullable();
            $table->string('openai_compatible_reasoning_effort')->nullable();
            $table->integer('claude_code_thinking_tokens')->nullable();
            $table->string('claude_session_id')->nullable();
            $table->string('codex_session_id')->nullable();
        });

        // Migrate data back from reasoning_config/provider_session_id
        // (reverse migration would need to parse JSON -- best effort)

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['reasoning_config', 'provider_session_id']);
        });

        // Agents: restore old columns
        Schema::table('agents', function (Blueprint $table) {
            $table->integer('anthropic_thinking_budget')->nullable();
            $table->string('openai_reasoning_effort', 20)->nullable();
            $table->string('openai_compatible_reasoning_effort')->nullable();
            $table->integer('claude_code_thinking_tokens')->nullable();
            $table->string('codex_reasoning_effort')->nullable();
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['reasoning_config']);
        });
    }
};
