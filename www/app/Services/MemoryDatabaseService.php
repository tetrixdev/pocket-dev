<?php

namespace App\Services;

use App\Models\MemoryDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing memory databases (PostgreSQL schemas).
 * Handles creation, deletion, and schema operations.
 */
class MemoryDatabaseService
{
    /**
     * Create a new memory database (PostgreSQL schema).
     *
     * @param string $name Display name
     * @param string $schemaName Schema name (without memory_ prefix)
     * @param string|null $description Optional description
     * @return array{success: bool, memory_database: ?MemoryDatabase, message: string}
     */
    public function create(string $name, string $schemaName, ?string $description = null): array
    {
        // Validate schema name
        if (!MemoryDatabase::isValidSchemaName($schemaName)) {
            return [
                'success' => false,
                'memory_database' => null,
                'message' => 'Schema name must be lowercase, start with a letter, contain only letters/numbers/underscores, and be max 55 characters',
            ];
        }

        // Check if schema already exists
        $fullSchemaName = 'memory_' . $schemaName;
        if ($this->schemaExists($fullSchemaName)) {
            return [
                'success' => false,
                'memory_database' => null,
                'message' => "Schema '{$fullSchemaName}' already exists",
            ];
        }

        try {
            // Use a transaction that spans both PostgreSQL schema creation and model creation
            // to ensure atomicity - if either fails, both are rolled back
            $memoryDb = DB::connection('pgsql')->transaction(function () use ($fullSchemaName, $schemaName, $name, $description) {
                // Create the PostgreSQL schema
                DB::connection('pgsql')->statement("CREATE SCHEMA {$fullSchemaName}");

                // Create schema_registry table
                DB::connection('pgsql')->statement("
                    CREATE TABLE {$fullSchemaName}.schema_registry (
                        table_name TEXT PRIMARY KEY,
                        description TEXT,
                        embeddable_fields TEXT[] DEFAULT '{}',
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ");

                // Create embeddings table with vector support
                DB::connection('pgsql')->statement("
                    CREATE TABLE {$fullSchemaName}.embeddings (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        source_table TEXT NOT NULL,
                        source_id UUID NOT NULL,
                        field_name TEXT NOT NULL,
                        chunk_index INTEGER DEFAULT 0,
                        content TEXT,
                        content_hash VARCHAR(64),
                        embedding vector(1536),
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW(),
                        UNIQUE(source_table, source_id, field_name, chunk_index)
                    )
                ");

                // Create indexes for embeddings
                DB::connection('pgsql')->statement("
                    CREATE INDEX idx_{$schemaName}_embeddings_source
                    ON {$fullSchemaName}.embeddings(source_table, source_id)
                ");

                DB::connection('pgsql')->statement("
                    CREATE INDEX idx_{$schemaName}_embeddings_hnsw
                    ON {$fullSchemaName}.embeddings
                    USING hnsw (embedding vector_cosine_ops)
                ");

                // Create skills table for slash commands
                DB::connection('pgsql')->statement("
                    CREATE TABLE {$fullSchemaName}.skills (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name TEXT NOT NULL,
                        when_to_use TEXT NOT NULL,
                        instructions TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW(),
                        CONSTRAINT skills_name_unique UNIQUE (name)
                    )
                ");

                // Create index for skills name lookups
                DB::connection('pgsql')->statement("
                    CREATE INDEX idx_{$schemaName}_skills_name ON {$fullSchemaName}.skills(name)
                ");

                // Add column comments for AI-consumable documentation
                DB::connection('pgsql')->statement("
                    COMMENT ON COLUMN {$fullSchemaName}.skills.name IS 'The exact trigger name (e.g., \"commit\", \"review-pr\")'
                ");
                DB::connection('pgsql')->statement("
                    COMMENT ON COLUMN {$fullSchemaName}.skills.when_to_use IS 'Conditions/situations when this skill should be invoked'
                ");
                DB::connection('pgsql')->statement("
                    COMMENT ON COLUMN {$fullSchemaName}.skills.instructions IS 'Full skill instructions in markdown format'
                ");

                // Register skills in schema_registry with proper description format
                DB::connection('pgsql')->statement("
                    INSERT INTO {$fullSchemaName}.schema_registry (table_name, description, embeddable_fields, created_at, updated_at)
                    VALUES (
                        'skills',
                        'PocketDev Skills - slash commands that can be invoked via /name in chat.
**Typical queries:** Get skill by exact name match
**Relationships:** None (standalone table)
**Example:** pd memory:query --schema={$schemaName} --sql=\"SELECT instructions FROM memory_{$schemaName}.skills WHERE name = ''example-skill''\"',
                        '{\"when_to_use\",\"instructions\"}',
                        NOW(),
                        NOW()
                    )
                ");

                // Grant permissions to memory_ai user
                DB::connection('pgsql')->statement("GRANT USAGE ON SCHEMA {$fullSchemaName} TO memory_ai");
                DB::connection('pgsql')->statement("GRANT CREATE ON SCHEMA {$fullSchemaName} TO memory_ai");
                DB::connection('pgsql')->statement("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA {$fullSchemaName} TO memory_ai");
                DB::connection('pgsql')->statement("REVOKE DELETE ON {$fullSchemaName}.embeddings FROM memory_ai");
                DB::connection('pgsql')->statement("REVOKE TRUNCATE ON {$fullSchemaName}.schema_registry FROM memory_ai");
                DB::connection('pgsql')->statement("REVOKE TRUNCATE ON {$fullSchemaName}.skills FROM memory_ai");

                // Grant permissions to memory_readonly user
                DB::connection('pgsql')->statement("GRANT USAGE ON SCHEMA {$fullSchemaName} TO memory_readonly");
                DB::connection('pgsql')->statement("GRANT SELECT ON ALL TABLES IN SCHEMA {$fullSchemaName} TO memory_readonly");

                // Set default privileges for future tables
                DB::connection('pgsql')->statement("
                    ALTER DEFAULT PRIVILEGES IN SCHEMA {$fullSchemaName}
                    GRANT SELECT ON TABLES TO memory_readonly
                ");

                // Create the MemoryDatabase record INSIDE the transaction
                // If this fails, the schema creation will also be rolled back
                return MemoryDatabase::create([
                    'name' => $name,
                    'schema_name' => $schemaName,
                    'description' => $description,
                ]);
            });

            Log::info('Memory database created', [
                'id' => $memoryDb->id,
                'name' => $name,
                'schema' => $fullSchemaName,
            ]);

            return [
                'success' => true,
                'memory_database' => $memoryDb,
                'message' => "Memory database '{$name}' created successfully",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create memory database', [
                'name' => $name,
                'schema' => $fullSchemaName,
                'error' => $e->getMessage(),
            ]);

            // Try to clean up if schema was partially created
            try {
                if ($this->schemaExists($fullSchemaName)) {
                    DB::connection('pgsql')->statement("DROP SCHEMA {$fullSchemaName} CASCADE");
                }
            } catch (\Exception $cleanupError) {
                Log::warning('Failed to clean up schema after error', [
                    'schema' => $fullSchemaName,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'memory_database' => null,
                'message' => 'Failed to create memory database: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a memory database (PostgreSQL schema).
     *
     * @param MemoryDatabase $memoryDb The memory database to delete
     * @param bool $dropSchema Whether to drop the PostgreSQL schema
     * @return array{success: bool, message: string}
     */
    public function delete(MemoryDatabase $memoryDb, bool $dropSchema = false): array
    {
        $fullSchemaName = $memoryDb->getFullSchemaName();

        try {
            if ($dropSchema && $this->schemaExists($fullSchemaName)) {
                DB::connection('pgsql')->statement("DROP SCHEMA {$fullSchemaName} CASCADE");
                Log::info('Memory database schema dropped', ['schema' => $fullSchemaName]);
            }

            $memoryDb->delete(); // Soft delete

            return [
                'success' => true,
                'message' => $dropSchema
                    ? "Memory database '{$memoryDb->name}' and schema deleted"
                    : "Memory database '{$memoryDb->name}' deleted (schema preserved)",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to delete memory database', [
                'id' => $memoryDb->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to delete memory database: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Rename a memory database schema.
     *
     * @param MemoryDatabase $memoryDb The memory database
     * @param string $newSchemaName New schema name (without memory_ prefix)
     * @return array{success: bool, message: string}
     */
    public function changeSchemaName(MemoryDatabase $memoryDb, string $newSchemaName): array
    {
        if (!MemoryDatabase::isValidSchemaName($newSchemaName)) {
            return [
                'success' => false,
                'message' => 'Invalid schema name format',
            ];
        }

        $oldFullSchemaName = $memoryDb->getFullSchemaName();
        $newFullSchemaName = 'memory_' . $newSchemaName;

        if ($this->schemaExists($newFullSchemaName)) {
            return [
                'success' => false,
                'message' => "Schema '{$newFullSchemaName}' already exists",
            ];
        }

        try {
            DB::connection('pgsql')->transaction(function () use ($oldFullSchemaName, $newFullSchemaName, $memoryDb, $newSchemaName) {
                // Rename the PostgreSQL schema
                DB::connection('pgsql')->statement(
                    "ALTER SCHEMA {$oldFullSchemaName} RENAME TO {$newFullSchemaName}"
                );

                // Update the record
                $memoryDb->update(['schema_name' => $newSchemaName]);
            });

            Log::info('Memory database schema renamed', [
                'id' => $memoryDb->id,
                'old_schema' => $oldFullSchemaName,
                'new_schema' => $newFullSchemaName,
            ]);

            return [
                'success' => true,
                'message' => "Schema renamed from '{$oldFullSchemaName}' to '{$newFullSchemaName}'",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to rename memory database schema', [
                'id' => $memoryDb->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to rename schema: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a PostgreSQL schema exists.
     */
    public function schemaExists(string $schemaName): bool
    {
        $result = DB::connection('pgsql')->selectOne("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.schemata WHERE schema_name = ?
            ) as exists
        ", [$schemaName]);

        return (bool) $result->exists;
    }

    /**
     * Get statistics for a memory database.
     */
    public function getStats(MemoryDatabase $memoryDb): array
    {
        $fullSchemaName = $memoryDb->getFullSchemaName();

        if (!$this->schemaExists($fullSchemaName)) {
            return [
                'exists' => false,
                'tables' => 0,
                'embeddings' => 0,
            ];
        }

        $tableCount = DB::connection('pgsql_readonly')->selectOne("
            SELECT COUNT(*) as count FROM information_schema.tables
            WHERE table_schema = ? AND table_name NOT IN ('schema_registry', 'embeddings')
        ", [$fullSchemaName]);

        $embeddingCount = DB::connection('pgsql_readonly')->selectOne("
            SELECT COUNT(*) as count FROM {$fullSchemaName}.embeddings
        ");

        return [
            'exists' => true,
            'tables' => (int) ($tableCount->count ?? 0),
            'embeddings' => (int) ($embeddingCount->count ?? 0),
        ];
    }
}
