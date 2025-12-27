<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the memory schema and enable required extensions.
     */
    public function up(): void
    {
        // Create memory schema for AI-managed tables
        DB::statement('CREATE SCHEMA IF NOT EXISTS memory');

        // Enable PostGIS for spatial queries (coordinates, distance calculations)
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // Enable pg_trgm for fuzzy text search (indexed ILIKE, similarity matching)
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Note: pgvector extension should already exist from previous migrations
        // but ensure it's available
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    /**
     * Drop the memory schema and extensions.
     */
    public function down(): void
    {
        // Drop schema cascades all tables within it
        DB::statement('DROP SCHEMA IF EXISTS memory CASCADE');

        // Note: We don't drop extensions as they may be used by other parts of the system
        // and dropping them could break things. Extensions are generally kept.
    }
};
