<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('panel_states', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Reference to the panel tool (by slug)
            $table->string('panel_slug');

            // Parameters used to open this panel instance (e.g., {"path": "/workspace"})
            $table->json('parameters')->nullable();

            // Current interaction state (e.g., {"expanded": ["/src"], "selected": null})
            $table->json('state')->nullable();

            $table->timestamps();

            // Index for finding panel states by slug
            $table->index('panel_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_states');
    }
};
