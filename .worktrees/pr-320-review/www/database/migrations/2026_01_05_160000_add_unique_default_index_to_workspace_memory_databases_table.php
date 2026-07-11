<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a unique partial index to ensure only one memory database
     * can be marked as default per workspace.
     */
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX idx_workspace_memory_db_single_default
            ON workspace_memory_databases (workspace_id)
            WHERE is_default = true
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_workspace_memory_db_single_default');
    }
};
