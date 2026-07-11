<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the schema registry table in the memory schema.
     * This table tracks metadata about user-created tables.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE memory.schema_registry (
                table_name TEXT PRIMARY KEY,
                description TEXT,
                embeddable_fields TEXT[] DEFAULT '{}',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Add table comment
        DB::statement("COMMENT ON TABLE memory.schema_registry IS 'Tracks table metadata. Automatically populated by memory:schema:create-table. DO NOT DROP.'");

        // Add column comments
        DB::statement("COMMENT ON COLUMN memory.schema_registry.table_name IS 'Name of the table (without schema prefix)'");
        DB::statement("COMMENT ON COLUMN memory.schema_registry.description IS 'Human-readable description of what this table stores'");
        DB::statement("COMMENT ON COLUMN memory.schema_registry.embeddable_fields IS 'Array of field names that should be auto-embedded on insert/update'");
    }

    /**
     * Drop the schema registry table.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS memory.schema_registry');
    }
};
