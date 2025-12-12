<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider_type', 50);     // 'anthropic', 'openai', 'claude_code'
            $table->string('model', 100);            // Can change mid-conversation
            $table->string('title')->nullable();
            $table->string('working_directory', 500);

            // Token tracking (cumulative)
            $table->unsignedInteger('total_input_tokens')->default(0);
            $table->unsignedInteger('total_output_tokens')->default(0);

            // Status: idle, processing, archived, failed
            $table->string('status', 20)->default('idle');

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('provider_type');
            $table->index('status');
            $table->index('last_activity_at');
            $table->index('working_directory');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
