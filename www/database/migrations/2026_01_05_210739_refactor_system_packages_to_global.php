<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Refactors system_packages to be purely global (removes workspace_id).
     * Adds selected_packages to workspaces table for workspace-specific visibility.
     */
    public function up(): void
    {
        // First, deduplicate packages - keep only unique names (consolidate to global)
        // Get all unique package names
        $uniquePackages = DB::table('system_packages')
            ->select('name')
            ->distinct()
            ->pluck('name');

        // Delete all existing packages
        DB::table('system_packages')->truncate();

        // Remove workspace_id from system_packages (make purely global)
        Schema::table('system_packages', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropIndex('system_packages_workspace_id_index');
            $table->dropUnique('system_packages_name_workspace_id_unique');
            $table->dropColumn('workspace_id');
            $table->unique('name'); // Now just unique by name globally
        });

        // Re-insert unique packages as global
        foreach ($uniquePackages as $name) {
            DB::table('system_packages')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Add selected_packages to workspaces (like allowed_tools)
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('selected_packages')->nullable()->after('settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('selected_packages');
        });

        Schema::table('system_packages', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->index('workspace_id');
            $table->dropUnique(['name']);
            $table->unique(['name', 'workspace_id']);
        });
    }
};
