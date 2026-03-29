<?php

namespace Database\Seeders;

use App\Skills\SystemSkillDefinitions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeds system skills into all memory schemas.
 *
 * This seeder is idempotent - it uses upsert logic to:
 * - Insert new system skills
 * - Update existing system skills (only if source = 'system' AND content changed)
 * - Never overwrite user-customized skills
 * - Skip updates when content is identical (avoids unnecessary embedding regeneration)
 *
 * ## When to run this seeder
 *
 * 1. **First install**: Called automatically from the migration that adds system skill columns
 * 2. **Adding/updating skills**: Create a new migration that calls this seeder:
 *
 *    ```php
 *    // database/migrations/2026_XX_XX_add_new_system_skill.php
 *    public function up(): void
 *    {
 *        Artisan::call('db:seed', [
 *            '--class' => \Database\Seeders\SystemSkillSeeder::class,
 *            '--force' => true,
 *        ]);
 *    }
 *    ```
 *
 * This pattern ensures:
 * - Skills are automatically deployed on `php artisan migrate`
 * - No embedding costs on regular deploys (only when skills actually change)
 * - Changes are tracked in migration history
 *
 * Run manually via: php artisan db:seed --class=SystemSkillSeeder
 */
class SystemSkillSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $skills = SystemSkillDefinitions::all();
        $version = SystemSkillDefinitions::VERSION;

        // Get all memory schemas
        $schemas = DB::connection('pgsql')->select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'memory_%'
        ");

        if (empty($schemas)) {
            $this->command->info('No memory schemas found. System skills will be seeded when schemas are created.');
            return;
        }

        $this->command->info("Seeding " . count($skills) . " system skill(s) into " . count($schemas) . " schema(s)...");

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
                $this->command->warn("Schema {$schemaName} does not have updated skills table. Run migrations first.");
                continue;
            }

            foreach ($skills as $skill) {
                $this->seedSkill($schemaName, $skill, $version);
            }

            $this->command->info("  Seeded skills into {$schemaName}");
        }

        $this->command->info('System skills seeded successfully.');
        $this->command->warn('Note: Embeddings will be generated when skills are first accessed or via manual embedding regeneration.');
    }

    /**
     * Seed a single skill into a schema.
     */
    private function seedSkill(string $schemaName, array $skill, string $version): void
    {
        $tagsArray = $this->arrayToPgArray($skill['tags']);

        // Use INSERT ... ON CONFLICT with WHERE clause to:
        // 1. Only update system skills (not user-customized)
        // 2. Only update if content actually changed (avoids unnecessary embedding regeneration)
        DB::connection('pgsql')->statement("
            INSERT INTO {$schemaName}.skills
            (name, when_to_use, instructions, source, tags, version, created_at, updated_at)
            VALUES (?, ?, ?, 'system', ?, ?, NOW(), NOW())
            ON CONFLICT (name) DO UPDATE SET
                when_to_use = EXCLUDED.when_to_use,
                instructions = EXCLUDED.instructions,
                tags = EXCLUDED.tags,
                version = EXCLUDED.version,
                updated_at = NOW()
            WHERE {$schemaName}.skills.source = 'system'
              AND ({$schemaName}.skills.when_to_use IS DISTINCT FROM EXCLUDED.when_to_use
                   OR {$schemaName}.skills.instructions IS DISTINCT FROM EXCLUDED.instructions
                   OR {$schemaName}.skills.tags IS DISTINCT FROM EXCLUDED.tags)
        ", [
            $skill['name'],
            $skill['when_to_use'],
            $skill['instructions'],
            $tagsArray,
            $version,
        ]);
    }

    /**
     * Convert PHP array to PostgreSQL array literal.
     */
    private function arrayToPgArray(array $values): string
    {
        $escaped = array_map(function ($v) {
            // Escape double quotes and backslashes
            $v = str_replace('\\', '\\\\', $v);
            $v = str_replace('"', '\\"', $v);
            return '"' . $v . '"';
        }, $values);

        return '{' . implode(',', $escaped) . '}';
    }
}
