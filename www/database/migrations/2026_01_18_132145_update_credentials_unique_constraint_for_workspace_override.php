<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Update the unique constraint on credentials to allow the same slug
 * to exist both globally (workspace_id IS NULL) and per-workspace.
 *
 * This enables workspace-specific credentials to override global ones.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing unique constraint on slug alone
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });

        // Create partial unique index for workspace-specific credentials
        // (slug + workspace_id must be unique when workspace_id is not null)
        DB::statement('
            CREATE UNIQUE INDEX credentials_slug_workspace_unique
            ON credentials (slug, workspace_id)
            WHERE workspace_id IS NOT NULL
        ');

        // Create partial unique index for global credentials
        // (slug must be unique when workspace_id IS NULL)
        DB::statement('
            CREATE UNIQUE INDEX credentials_slug_global_unique
            ON credentials (slug)
            WHERE workspace_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the partial indexes
        DB::statement('DROP INDEX IF EXISTS credentials_slug_workspace_unique');
        DB::statement('DROP INDEX IF EXISTS credentials_slug_global_unique');

        // Restore the original unique constraint on slug alone
        Schema::table('credentials', function (Blueprint $table) {
            $table->unique('slug');
        });
    }
};
