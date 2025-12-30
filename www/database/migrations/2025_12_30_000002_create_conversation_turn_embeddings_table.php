<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create table with vector column
        DB::statement("
            CREATE TABLE conversation_turn_embeddings (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                turn_number INTEGER NOT NULL,
                chunk_number INTEGER NOT NULL DEFAULT 0,
                embedding vector(1536) NOT NULL,
                content_preview TEXT,
                content_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(conversation_id, turn_number, chunk_number)
            )
        ");

        // Index for looking up embeddings by conversation
        DB::statement('CREATE INDEX idx_cte_conversation ON conversation_turn_embeddings(conversation_id)');

        // HNSW index for fast vector similarity search
        DB::statement('CREATE INDEX idx_cte_hnsw ON conversation_turn_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS conversation_turn_embeddings');
    }
};
