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
        Schema::table('claude_sessions', function (Blueprint $table) {
            $table->integer('process_pid')->nullable()->after('status');
            $table->enum('process_status', ['idle', 'starting', 'streaming', 'completed', 'failed', 'cancelled'])
                ->default('idle')
                ->after('process_pid');
            $table->integer('last_message_index')->default(0)->after('process_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claude_sessions', function (Blueprint $table) {
            $table->dropColumn(['process_pid', 'process_status', 'last_message_index']);
        });
    }
};
