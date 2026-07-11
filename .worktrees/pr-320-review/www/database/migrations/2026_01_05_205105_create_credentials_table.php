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
        Schema::create('credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 255)->unique();      // e.g., 'github_token'
            $table->string('env_var', 255);             // e.g., 'GITHUB_TOKEN' or 'GH_TOKEN'
            $table->text('encrypted_value');            // Encrypted by Laravel Crypt
            $table->text('description')->nullable();    // Optional notes for user
            $table->timestamps();

            // Index for quick lookups by env_var
            $table->index('env_var');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
