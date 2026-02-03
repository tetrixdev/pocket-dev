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
        Schema::create('pocketdev_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Workspace this session belongs to
            $table->foreignUuid('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();

            // User-editable session name
            $table->string('name');

            // Soft archive (hidden from default list but preserved)
            $table->boolean('is_archived')->default(false);

            // Track which screen was last active (set after screens table exists)
            $table->uuid('last_active_screen_id')->nullable();

            // Screen display order (array of screen UUIDs)
            $table->json('screen_order')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('workspace_id');
            $table->index('is_archived');
            $table->index(['workspace_id', 'is_archived']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocketdev_sessions');
    }
};
