<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds "inherit from workspace" settings for tools and memory schemas.
     * When enabled, the agent dynamically uses whatever is enabled in its workspace.
     * When disabled, the agent uses its specific allowed_tools/memory_schemas selections.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Default to true so existing agents with allowed_tools=null behave the same
            $table->boolean('inherit_workspace_tools')->default(true)->after('allowed_tools');
            // Default to false for schemas since existing agents have explicit schema selections
            $table->boolean('inherit_workspace_schemas')->default(false)->after('inherit_workspace_tools');
        });

        // Migrate existing data: agents with allowed_tools=null should have inherit_workspace_tools=true
        // agents with specific allowed_tools should have inherit_workspace_tools=false
        DB::table('agents')
            ->whereNotNull('allowed_tools')
            ->update(['inherit_workspace_tools' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['inherit_workspace_tools', 'inherit_workspace_schemas']);
        });
    }
};
