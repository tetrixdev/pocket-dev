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
        Schema::table('workspace_memory_databases', function (Blueprint $table) {
            $table->index('memory_database_id');
        });

        Schema::table('agent_memory_databases', function (Blueprint $table) {
            $table->index('memory_database_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_memory_databases', function (Blueprint $table) {
            $table->dropIndex(['memory_database_id']);
        });

        Schema::table('agent_memory_databases', function (Blueprint $table) {
            $table->dropIndex(['memory_database_id']);
        });
    }
};
