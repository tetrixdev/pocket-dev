<?php

use Database\Seeders\SystemSkillSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Add columns to support system skills (shipped with PocketDev):
 * - source: 'system' or 'user' to distinguish origin
 * - tags: array of grouping labels for filtering
 * - version: PocketDev version that last updated the skill (for system skills)
 *
 * After adding columns, seeds system skills via SystemSkillSeeder.
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
                SELECT (EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = ? AND table_name = 'skills'
                ))::int as exists
            ", [$schemaName]);

            if ((int) $exists->exists !== 1) {
                continue;
            }

            // Check if source column already exists
            $hasSourceColumn = DB::connection('pgsql')->selectOne("
                SELECT (EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ? AND table_name = 'skills' AND column_name = 'source'
                ))::int as exists
            ", [$schemaName]);

            if ((int) $hasSourceColumn->exists === 1) {
                continue;
            }

            // Add new columns
            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                ADD COLUMN source VARCHAR(20) DEFAULT 'user' NOT NULL,
                ADD COLUMN tags TEXT[] DEFAULT '{}',
                ADD COLUMN version VARCHAR(20) DEFAULT NULL
            ");

            // Add column comments
            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.source IS 'Origin: ''system'' for PocketDev-shipped skills, ''user'' for user-created'
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.tags IS 'Grouping labels for filtering, e.g., {\"system\",\"deployment\"}'
            ");

            DB::connection('pgsql')->statement("
                COMMENT ON COLUMN {$schemaName}.skills.version IS 'PocketDev version that last updated this skill (system skills only)'
            ");

            // Tag existing skills as user-created
            DB::connection('pgsql')->statement("
                UPDATE {$schemaName}.skills
                SET source = 'user', tags = '{\"user\"}'
                WHERE source = 'user'
            ");

            // Create index on source for efficient filtering
            DB::connection('pgsql')->statement("
                CREATE INDEX IF NOT EXISTS idx_{$schemaName}_skills_source ON {$schemaName}.skills(source)
            ");

            // Create GIN index on tags for array containment queries
            DB::connection('pgsql')->statement("
                CREATE INDEX IF NOT EXISTS idx_{$schemaName}_skills_tags ON {$schemaName}.skills USING GIN (tags)
            ");
        }

        // Seed system skills into all schemas
        Artisan::call('db:seed', [
            '--class' => SystemSkillSeeder::class,
            '--force' => true,
        ]);
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

            // Check if skills table exists with source column
            $hasSourceColumn = DB::connection('pgsql')->selectOne("
                SELECT (EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ? AND table_name = 'skills' AND column_name = 'source'
                ))::int as exists
            ", [$schemaName]);

            if ((int) $hasSourceColumn->exists !== 1) {
                continue;
            }

            // Drop indexes
            DB::connection('pgsql')->statement("
                DROP INDEX IF EXISTS {$schemaName}.idx_{$schemaName}_skills_source
            ");

            DB::connection('pgsql')->statement("
                DROP INDEX IF EXISTS {$schemaName}.idx_{$schemaName}_skills_tags
            ");

            // Remove columns
            DB::connection('pgsql')->statement("
                ALTER TABLE {$schemaName}.skills
                DROP COLUMN IF EXISTS source,
                DROP COLUMN IF EXISTS tags,
                DROP COLUMN IF EXISTS version
            ");
        }
    }
};
