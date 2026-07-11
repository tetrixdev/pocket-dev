<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy claude_sessions table.
 *
 * This table was used by the original chat implementation (ClaudeController)
 * which has been replaced by the multi-provider conversation system (chat-v2).
 * The new system uses the 'conversations' table instead.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('claude_sessions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the table structure for rollback purposes only
        Schema::create('claude_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('claude_session_id')->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('project_path');
            $table->string('model')->default('claude-sonnet-4-5-20250929');
            $table->integer('turn_count')->default(0);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('process_pid')->nullable();
            $table->string('process_status')->nullable();
            $table->unsignedInteger('last_message_index')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
            $table->index('project_path');
        });
    }
};
