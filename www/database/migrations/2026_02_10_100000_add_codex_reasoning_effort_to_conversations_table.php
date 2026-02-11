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
            $table->string('codex_reasoning_effort', 20)
                ->nullable()
                ->after('claude_code_thinking_tokens')
                ->comment('Codex reasoning effort: minimal, low, medium, high, xhigh');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('codex_reasoning_effort');
        });
    }
};
