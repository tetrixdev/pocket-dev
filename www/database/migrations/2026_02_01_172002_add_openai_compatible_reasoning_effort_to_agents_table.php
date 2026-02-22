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
        Schema::table('agents', function (Blueprint $table) {
            $table->string('openai_compatible_reasoning_effort', 20)
                ->nullable()
                ->after('openai_reasoning_effort')
                ->comment('Reasoning effort for OpenAI-compatible providers: none, low, medium, high');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('openai_compatible_reasoning_effort');
        });
    }
};
