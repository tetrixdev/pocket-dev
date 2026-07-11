<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('object_id');
            $table->string('field_path', 255);          // 'description', 'history', etc.
            $table->string('content_hash', 64)->nullable(); // To detect changes
            $table->timestamp('created_at')->nullable();

            $table->foreign('object_id')
                ->references('id')
                ->on('memory_objects')
                ->cascadeOnDelete();

            $table->unique(['object_id', 'field_path']);
            $table->index(['object_id']);
        });

        // Add vector column using raw SQL (pgvector)
        // Using 1536 dimensions for OpenAI text-embedding-3-small
        // Can also use 3072 for text-embedding-3-large
        DB::statement('ALTER TABLE memory_embeddings ADD COLUMN embedding vector(1536)');

        // Create HNSW index for fast similarity search
        DB::statement('CREATE INDEX memory_embeddings_embedding_idx ON memory_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_embeddings');
    }
};
