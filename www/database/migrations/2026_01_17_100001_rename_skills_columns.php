<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rename skills table columns for clarity and add proper documentation:
 * - description -> when_to_use (conditions when skill should be used)
 * - content -> instructions (the full skill instructions/template)
 * - Add COMMENT ON COLUMN for AI-consumable documentation
 * - Update schema_registry with proper format
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

            // Check if columns need renaming (description exists, when_to_use doesn't)
            $hasOldColumns = DB::connection('pgsql')->selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ? AND table_name = 'skills' AND column_name = 'description'
                ) as exists
            ", [$schemaName]);

            if (!$hasOldColumns->exists) {
                Log::info("Skills table in {$schemaName} already has new column names, skipping");
                continue;
            }

            Log::info("Renaming skills columns in {$schemaName}");

            // Rename columns
            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                RENAME COLUMN description TO when_to_use
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                RENAME COLUMN content TO instructions
            ");

            // Add column comments for AI-consumable documentation
            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.name IS 'The exact trigger name (e.g., \"commit\", \"review-pr\")'
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.when_to_use IS 'Conditions/situations when this skill should be invoked'
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.instructions IS 'Full skill instructions in markdown format'
            ");

            // Update schema_registry with proper description format
            DB::connection('pgsql')->statement("
                UPDATE {$schemaName}.schema_registry
                SET
                    description = 'PocketDev Skills - slash commands that can be invoked via /name in chat.
**Typical queries:** Get skill by exact name match
**Relationships:** None (standalone table)
**Example:** php artisan memory:query --schema={$schemaName} --sql=\"SELECT instructions FROM skills WHERE name = ''example-skill''\"',
                    embeddable_fields = '{\"when_to_use\",\"instructions\"}',
                    updated_at = NOW()
                WHERE table_name = 'skills'
            ");

            // Update embeddings field_name references
            try {
                DB::connection('pgsql')->statement("
                    UPDATE {$schemaName}.embeddings
                    SET field_name = 'when_to_use'
                    WHERE source_table = 'skills' AND field_name = 'description'
                ");

                DB::connection('pgsql')->statement("
                    UPDATE {$schemaName}.embeddings
                    SET field_name = 'instructions'
                    WHERE source_table = 'skills' AND field_name = 'content'
                ");
            } catch (\Exception $e) {
                // field_name column may not exist in older schemas - skip
                Log::info("Skipping embeddings update in {$schemaName}: " . $e->getMessage());
            }

            Log::info("Skills columns renamed in {$schemaName}");
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

            // Check if skills table exists with new column names
            $hasNewColumns = DB::connection('pgsql')->selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ? AND table_name = 'skills' AND column_name = 'when_to_use'
                ) as exists
            ", [$schemaName]);

            if (!$hasNewColumns->exists) {
                continue;
            }

            Log::info("Reverting skills columns in {$schemaName}");

            // Rename columns back
            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                RENAME COLUMN when_to_use TO description
            ");

            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                RENAME COLUMN instructions TO content
            ");

            // Remove column comments (set to null)
            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.name IS NULL
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.description IS NULL
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.content IS NULL
            ");

            // Revert schema_registry
            DB::connection('pgsql')->statement("
                UPDATE {$schemaName}.schema_registry
                SET
                    description = 'Slash commands and skills that can be invoked via /name. Each skill has a name (the command), description (when to use), and content (full instructions).',
                    embeddable_fields = '{\"description\",\"content\"}',
                    updated_at = NOW()
                WHERE table_name = 'skills'
            ");

            // Revert embeddings field_name references
            try {
                DB::connection('pgsql')->statement("
                    UPDATE {$schemaName}.embeddings
                    SET field_name = 'description'
                    WHERE source_table = 'skills' AND field_name = 'when_to_use'
                ");

                DB::connection('pgsql')->statement("
                    UPDATE {$schemaName}.embeddings
                    SET field_name = 'content'
                    WHERE source_table = 'skills' AND field_name = 'instructions'
                ");
            } catch (\Exception $e) {
                // field_name column may not exist in older schemas - skip
                Log::info("Skipping embeddings revert in {$schemaName}: " . $e->getMessage());
            }

            Log::info("Skills columns reverted in {$schemaName}");
        }
    }
};
