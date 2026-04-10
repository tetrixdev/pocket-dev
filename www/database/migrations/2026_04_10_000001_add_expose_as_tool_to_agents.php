<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Opt-in: expose this agent as a dedicated native tool call
            $table->boolean('expose_as_tool')->default(false)->after('enabled');

            // Caller-side: can this agent call other agents as sub-agents?
            $table->boolean('can_call_subagents')->default(true)->after('expose_as_tool');

            // Caller-side allowlist: if set, only these agent UUIDs are injected as tools
            // null = all opted-in agents; [] = (not used — toggle off can_call_subagents instead)
            $table->jsonb('allowed_subagents')->nullable()->after('can_call_subagents');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['expose_as_tool', 'can_call_subagents', 'allowed_subagents']);
        });
    }
};
