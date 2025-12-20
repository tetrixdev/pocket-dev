<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('memory_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('slug', 100);
            $table->text('description')->nullable();    // For AI system prompt
            $table->jsonb('schema');                    // JSON Schema with x-embed markers
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();     // Hex color
            $table->timestamps();

            $table->unique('slug');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_structures');
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
