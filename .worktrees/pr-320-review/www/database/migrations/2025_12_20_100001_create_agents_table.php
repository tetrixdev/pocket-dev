<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Provider Configuration
            $table->string('provider', 50);  // anthropic, openai, claude_code
            $table->string('model', 100);

            // Provider-specific reasoning settings (only one populated based on provider)
            $table->unsignedInteger('anthropic_thinking_budget')->nullable();
            $table->string('openai_reasoning_effort', 20)->nullable();  // none, low, medium, high
            $table->unsignedInteger('claude_code_thinking_tokens')->nullable();

            // Response configuration
            $table->unsignedTinyInteger('response_level')->default(1);  // 0-3

            // Tool configuration
            $table->json('allowed_tools')->nullable();  // null = all tools allowed

            // Custom system prompt (additional instructions)
            $table->text('system_prompt')->nullable();

            // Metadata
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // Indexes
            $table->index(['provider', 'enabled']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
