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
        Schema::create('claude_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('project_path');
            $table->json('messages')->nullable();
            $table->json('context')->nullable();
            $table->string('model')->default('claude-sonnet-4-5-20250929');
            $table->integer('turn_count')->default(0);
            $table->string('status')->default('active'); // active, completed, failed
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
            $table->index('project_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claude_sessions');
    }
};
