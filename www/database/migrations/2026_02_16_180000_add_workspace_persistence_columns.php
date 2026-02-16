<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add columns to persist workspace and session selection in the database
     * instead of relying on PHP session storage (which expires).
     *
     * - last_accessed_at: Timestamp of when workspace was last accessed
     *   Used to determine which workspace to show on app init
     *
     * - last_active_session_id: FK to the last active session in this workspace
     *   Used to restore the user's last session when returning to a workspace
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('last_accessed_at')->nullable()->index();
            $table->uuid('last_active_session_id')->nullable();

            $table->foreign('last_active_session_id')
                ->references('id')
                ->on('pocketdev_sessions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['last_active_session_id']);
            $table->dropColumn(['last_accessed_at', 'last_active_session_id']);
        });
    }
};
