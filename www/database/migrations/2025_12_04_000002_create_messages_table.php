<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Message identity
            // OpenAI uses 'tool' role; Anthropic uses 'user' with tool_result content
            $table->string('role', 20); // user, assistant, system, tool

            // Content stored in native provider format (JSON)
            $table->json('content');

            // Token tracking (per message)
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_creation_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();

            // Metadata
            $table->string('stop_reason', 50)->nullable();
            $table->string('model', 100)->nullable();

            // Ordering within conversation
            $table->unsignedInteger('sequence');

            $table->timestamp('created_at')->nullable();

            // Composite unique constraint to prevent race conditions
            $table->unique(['conversation_id', 'sequence']);
            $table->index(['conversation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
