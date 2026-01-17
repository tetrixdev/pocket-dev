<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Add skills table to all existing memory schemas.
 * Skills store slash commands that can be invoked via /name.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all memory schemas
        $schemas = DB::connection('pgsql')->select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'memory_%'
        ");

        foreach ($schemas as $schema) {
            $schemaName = $schema->schema_name;

            // Check if skills table already exists
            $exists = DB::connection('pgsql')->selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = ? AND table_name = 'skills'
                ) as exists
            ", [$schemaName]);

            if ($exists->exists) {
                Log::info("Skills table already exists in {$schemaName}, skipping");
                continue;
            }

            Log::info("Creating skills table in {$schemaName}");

            // Create skills table
            DB::connection('pgsql')->statement("
                CREATE TABLE {$schemaName}.skills (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    name TEXT NOT NULL,
                    when_to_use TEXT NOT NULL,
                    instructions TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW(),
                    CONSTRAINT skills_name_unique UNIQUE (name)
                )
            ");

            // Create index for name lookups
            DB::connection('pgsql')->statement("
                CREATE INDEX idx_{$schemaName}_skills_name ON {$schemaName}.skills(name)
            ");

            // Register in schema_registry with embed fields
            DB::connection('pgsql')->statement("
                INSERT INTO {$schemaName}.schema_registry (table_name, description, embeddable_fields, created_at, updated_at)
                VALUES (
                    'skills',
                    'PocketDev Skills - slash commands invoked via /name.

Columns:
- name: The exact trigger name (e.g., \"commit\", \"review-pr\")
- when_to_use: Conditions/situations when this skill should be invoked
- instructions: Full skill instructions in markdown format

To retrieve a skill, query by exact name match:
SELECT instructions FROM skills WHERE name = ''skill-name''',
                    '{\"when_to_use\",\"instructions\"}',
                    NOW(),
                    NOW()
                )
                ON CONFLICT (table_name) DO UPDATE SET
                    description = EXCLUDED.description,
                    embeddable_fields = EXCLUDED.embeddable_fields,
                    updated_at = NOW()
            ");

            // Grant permissions to memory_ai user
            DB::connection('pgsql')->statement("GRANT ALL PRIVILEGES ON {$schemaName}.skills TO memory_ai");

            // Grant SELECT to memory_readonly user
            DB::connection('pgsql')->statement("GRANT SELECT ON {$schemaName}.skills TO memory_readonly");

            Log::info("Skills table created in {$schemaName}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get all memory schemas
        $schemas = DB::connection('pgsql')->select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'memory_%'
        ");

        foreach ($schemas as $schema) {
            $schemaName = $schema->schema_name;

            // Check if skills table exists
            $exists = DB::connection('pgsql')->selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = ? AND table_name = 'skills'
                ) as exists
            ", [$schemaName]);

            if (!$exists->exists) {
                continue;
            }

            Log::info("Dropping skills table from {$schemaName}");

            // Delete embeddings for skills table
            DB::connection('pgsql')->statement("
                DELETE FROM {$schemaName}.embeddings WHERE source_table = 'skills'
            ");

            // Remove from schema_registry
            DB::connection('pgsql')->statement("
                DELETE FROM {$schemaName}.schema_registry WHERE table_name = 'skills'
            ");

            // Drop the table
            DB::connection('pgsql')->statement("DROP TABLE IF EXISTS {$schemaName}.skills");

            Log::info("Skills table dropped from {$schemaName}");
        }
    }
};
