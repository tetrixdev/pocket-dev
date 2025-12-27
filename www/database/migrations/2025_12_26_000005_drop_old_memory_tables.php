<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the old JSONB-based memory system tables.
     * This migration runs after the new memory schema is set up.
     */
    public function up(): void
    {
        // Drop old tables in correct order (respecting foreign keys)
        Schema::dropIfExists('memory_embeddings');
        Schema::dropIfExists('memory_objects');
        Schema::dropIfExists('memory_structures');

        // Note: We keep memory_readonly user as it's still used for memory:query
        // The memory_readonly user will need updated permissions for the new schema
        $this->updateReadonlyPermissions();
    }

    /**
     * Update memory_readonly user to have SELECT on new memory schema.
     */
    private function updateReadonlyPermissions(): void
    {
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_readonly'"
        );

        if ($userExists) {
            // Grant SELECT on memory schema
            DB::statement('GRANT USAGE ON SCHEMA memory TO memory_readonly');
            DB::statement('GRANT SELECT ON ALL TABLES IN SCHEMA memory TO memory_readonly');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA memory GRANT SELECT ON TABLES TO memory_readonly');
        }
    }

    /**
     * Recreate old tables (for rollback purposes).
     * Note: This won't restore data, just the structure.
     */
    public function down(): void
    {
        // Recreate memory_structures table
        if (!Schema::hasTable('memory_structures')) {
            DB::statement("
                CREATE TABLE memory_structures (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(100) UNIQUE NOT NULL,
                    description TEXT,
                    schema JSONB DEFAULT '{}',
                    icon VARCHAR(50),
                    color VARCHAR(7),
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");
        }

        // Recreate memory_objects table
        if (!Schema::hasTable('memory_objects')) {
            DB::statement("
                CREATE TABLE memory_objects (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    structure_id UUID REFERENCES memory_structures(id) ON DELETE CASCADE,
                    structure_slug VARCHAR(100),
                    name VARCHAR(255) NOT NULL,
                    data JSONB DEFAULT '{}',
                    searchable_text TEXT,
                    parent_id UUID REFERENCES memory_objects(id) ON DELETE SET NULL,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");
            DB::statement('CREATE INDEX memory_objects_structure_slug_idx ON memory_objects(structure_slug)');
            DB::statement('CREATE INDEX memory_objects_data_gin ON memory_objects USING GIN (data)');
        }

        // Recreate memory_embeddings table (old version in public schema)
        if (!Schema::hasTable('memory_embeddings')) {
            DB::statement("
                CREATE TABLE memory_embeddings (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    object_id UUID REFERENCES memory_objects(id) ON DELETE CASCADE,
                    field_path VARCHAR(255),
                    content_hash VARCHAR(64),
                    embedding vector(1536),
                    created_at TIMESTAMP DEFAULT NOW(),
                    UNIQUE(object_id, field_path)
                )
            ");
            DB::statement('CREATE INDEX memory_embeddings_hnsw ON memory_embeddings USING hnsw (embedding vector_cosine_ops)');
        }

        // Revoke memory_readonly permissions on memory schema
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_readonly'"
        );

        if ($userExists) {
            DB::statement('REVOKE ALL ON SCHEMA memory FROM memory_readonly');
        }
    }
};
