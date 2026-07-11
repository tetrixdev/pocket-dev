<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pocket_tools', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identity
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');

            // Classification
            $table->string('source')->default('user'); // 'pocketdev' | 'user'
            $table->string('category')->default('custom'); // 'memory' | 'tools' | 'file_ops' | 'custom'
            $table->string('capability')->nullable(); // 'bash' | 'file_read' | 'memory' | 'tool_mgmt' | 'custom'

            // Provider Compatibility
            $table->json('excluded_providers')->nullable(); // ['claude_code'] = not available for CC

            // For conflict detection with native tools
            $table->string('native_equivalent')->nullable(); // 'Bash' | 'Read' | etc.

            // AI Instructions
            $table->text('system_prompt');
            $table->json('input_schema')->nullable(); // JSON Schema for tool parameters

            // User tools only - bash script content (null for pocketdev tools)
            $table->text('script')->nullable();

            // State
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('source');
            $table->index('category');
            $table->index('capability');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pocket_tools');
    }
};
