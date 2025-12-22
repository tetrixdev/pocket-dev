<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tool settings table for storing runtime-configurable settings for native tools.
     * This allows toggling native tools on/off without modifying config files.
     */
    public function up(): void
    {
        Schema::create('tool_settings', function (Blueprint $table) {
            $table->string('provider', 64);      // e.g., 'claude_code', 'codex'
            $table->string('tool_name', 64);     // e.g., 'Bash', 'Read', 'shell_command'
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->primary(['provider', 'tool_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_settings');
    }
};
