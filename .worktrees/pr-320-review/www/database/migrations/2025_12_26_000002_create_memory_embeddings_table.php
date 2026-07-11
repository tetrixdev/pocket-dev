<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the central embeddings table in the memory schema.
     * This table stores all vector embeddings across all user tables.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE memory.embeddings (
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

        // Index for looking up embeddings by source
        DB::statement('CREATE INDEX idx_embeddings_source ON memory.embeddings(source_table, source_id)');

        // HNSW index for fast vector similarity search
        DB::statement('CREATE INDEX idx_embeddings_hnsw ON memory.embeddings USING hnsw (embedding vector_cosine_ops)');

        // Add table comment
        DB::statement("COMMENT ON TABLE memory.embeddings IS 'Central storage for semantic search vectors. Automatically populated by insert/update commands. DO NOT DROP.'");

        // Add column comments
        DB::statement("COMMENT ON COLUMN memory.embeddings.source_table IS 'Name of the table this embedding belongs to (without schema prefix)'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.source_id IS 'UUID of the row in the source table'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.field_name IS 'Name of the field that was embedded'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.chunk_index IS '0 = whole field, 1+ = chunk index for chunked embeddings (future)'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.content IS 'Original text content (for debugging and re-embedding)'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.content_hash IS 'SHA256 hash of content to detect changes'");
        DB::statement("COMMENT ON COLUMN memory.embeddings.embedding IS '1536-dimensional vector from embedding model'");
    }

    /**
     * Drop the embeddings table.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS memory.embeddings');
    }
};
