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
        Schema::create('screens', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Session this screen belongs to
            $table->foreignUuid('session_id')
                ->constrained('pocketdev_sessions')
                ->cascadeOnDelete();

            // Screen type: 'chat' for conversations, 'panel' for interactive panels
            $table->string('type'); // 'chat' or 'panel'

            // For type='chat': reference to conversation
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->foreign('conversation_id')
                ->references('id')
                ->on('conversations')
                ->nullOnDelete();

            // For type='panel': reference to panel tool and state
            $table->string('panel_slug')->nullable();
            $table->foreignUuid('panel_id')
                ->nullable()
                ->constrained('panel_states')
                ->nullOnDelete();

            // Panel parameters (redundant storage for quick access)
            $table->json('parameters')->nullable();

            // Whether this screen is currently active/visible
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('type');
            $table->index('conversation_id');
            $table->index('panel_slug');
        });

        // Add foreign key from sessions.last_active_screen_id to screens.id
        Schema::table('pocketdev_sessions', function (Blueprint $table) {
            $table->foreign('last_active_screen_id')
                ->references('id')
                ->on('screens')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pocketdev_sessions', function (Blueprint $table) {
            $table->dropForeign(['last_active_screen_id']);
        });

        Schema::dropIfExists('screens');
    }
};
