<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add extended_context boolean to agents table.
     *
     * When enabled (default: true), conversations created from this agent will use
     * the model's maximum supported context window (e.g. 1M tokens for Claude 4.6)
     * instead of the baseline context window (200K).
     *
     * Pricing note: extended context does not increase per-token cost — you only
     * pay for tokens actually used, regardless of window size.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('extended_context')->default(true)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('extended_context');
        });
    }
};
