<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_id');
            $table->uuid('target_id');
            $table->string('relationship_type', 100);   // 'owns', 'contains', 'knows', etc.
            $table->timestamp('created_at')->nullable();

            $table->foreign('source_id')
                ->references('id')
                ->on('memory_objects')
                ->cascadeOnDelete();

            $table->foreign('target_id')
                ->references('id')
                ->on('memory_objects')
                ->cascadeOnDelete();

            $table->unique(['source_id', 'target_id', 'relationship_type']);
            $table->index(['source_id']);
            $table->index(['target_id']);
            $table->index(['relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_relationships');
    }
};
