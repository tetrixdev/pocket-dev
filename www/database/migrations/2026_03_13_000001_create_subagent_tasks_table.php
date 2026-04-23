<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subagent_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Links to the parent conversation that spawned this
            $table->string('parent_conversation_uuid', 36)->nullable()->index();

            // Links to the child conversation running the task
            $table->string('child_conversation_uuid', 36)->index();

            // Which agent was used
            $table->uuid('agent_id')->index();

            // The prompt that was sent
            $table->text('prompt');

            // Whether this is a background task
            $table->boolean('is_background')->default(false);

            $table->timestamps();

            $table->foreign('child_conversation_uuid')
                ->references('uuid')
                ->on('conversations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subagent_tasks');
    }
};
