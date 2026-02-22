<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Update the unique constraint on agents.slug to be workspace-scoped.
 *
 * Before: slug must be globally unique
 * After: slug must be unique within each workspace (workspace_id + slug)
 *
 * This allows different workspaces to have agents with the same slug
 * (e.g., each workspace can have its own "default-codex" agent).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing unique constraint on slug alone
        Schema::table('agents', function ($table) {
            $table->dropUnique(['slug']);
        });

        // Create a composite unique index on (workspace_id, slug)
        // Using partial index to handle nullable workspace_id correctly:
        // - When workspace_id IS NOT NULL: (workspace_id, slug) must be unique
        // - When workspace_id IS NULL: slug must be unique among null workspace_ids
        DB::statement('
            CREATE UNIQUE INDEX agents_workspace_slug_unique
            ON agents (workspace_id, slug)
            WHERE workspace_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX agents_slug_global_unique
            ON agents (slug)
            WHERE workspace_id IS NULL
        ');
    }

    public function down(): void
    {
        // Check for duplicate slugs before rolling back
        $duplicates = DB::table('agents')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('slug');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot rollback: duplicate slugs exist that would violate unique constraint. ' .
                'Duplicates: ' . $duplicates->implode(', ')
            );
        }

        // Drop the partial indexes
        DB::statement('DROP INDEX IF EXISTS agents_workspace_slug_unique');
        DB::statement('DROP INDEX IF EXISTS agents_slug_global_unique');

        // Restore the original unique constraint on slug alone
        Schema::table('agents', function ($table) {
            $table->unique('slug');
        });
    }
};
