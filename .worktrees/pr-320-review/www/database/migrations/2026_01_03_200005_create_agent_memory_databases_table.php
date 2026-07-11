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
        Schema::create('agent_memory_databases', function (Blueprint $table) {
            $table->uuid('agent_id');
            $table->uuid('memory_database_id');
            $table->string('permission', 20)->default('write'); // read, write, admin
            $table->timestamps();

            $table->primary(['agent_id', 'memory_database_id']);

            $table->foreign('agent_id')
                ->references('id')
                ->on('agents')
                ->cascadeOnDelete();

            $table->foreign('memory_database_id')
                ->references('id')
                ->on('memory_databases')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_memory_databases');
    }
};
