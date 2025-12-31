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
        Schema::table('conversation_turn_embeddings', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('content_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_turn_embeddings', function (Blueprint $table) {
            $table->dropColumn('failed_at');
        });
    }
};
