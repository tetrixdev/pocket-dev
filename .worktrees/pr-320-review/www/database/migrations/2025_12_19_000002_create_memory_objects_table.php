<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_objects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('structure_id');
            $table->string('structure_slug', 100);      // Denormalized for faster filtering
            $table->string('name', 255);
            $table->jsonb('data')->default('{}');
            $table->text('searchable_text')->nullable(); // For full-text fallback
            $table->uuid('parent_id')->nullable();      // For hierarchy
            $table->timestamps();

            $table->foreign('structure_id')
                ->references('id')
                ->on('memory_structures')
                ->cascadeOnDelete();

            $table->index(['structure_slug']);
            $table->index(['structure_id']);
            $table->index(['parent_id']);
            $table->index(['created_at']);
        });

        // Add self-referential foreign key after table is created
        Schema::table('memory_objects', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('memory_objects')
                ->nullOnDelete();
        });

        // Create GIN index on JSONB data for efficient querying
        DB::statement('CREATE INDEX memory_objects_data_gin ON memory_objects USING GIN (data)');
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_objects');
    }
};
