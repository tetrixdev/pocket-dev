<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a unique constraint to prevent race conditions in sequence assignment.
     * This ensures that within a conversation, each message has a unique sequence number.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['conversation_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'sequence']);
        });
    }
};
