<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_conflicts', function (Blueprint $table) {
            $table->id();

            // Tool identifiers - can be pocket_tools.slug or native tool name (prefixed with 'native:')
            $table->string('tool_a_slug');
            $table->string('tool_b_slug');

            // Conflict type
            $table->string('conflict_type'); // 'equivalent' | 'incompatible'

            // Help text for resolution
            $table->text('resolution_hint')->nullable();

            $table->timestamps();

            // Unique constraint to prevent duplicate conflicts
            $table->unique(['tool_a_slug', 'tool_b_slug']);

            // Indexes for lookups
            $table->index('tool_a_slug');
            $table->index('tool_b_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_conflicts');
    }
};
