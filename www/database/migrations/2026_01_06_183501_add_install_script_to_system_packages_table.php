<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds install_script column for custom installation scripts.
     * Removes unique constraint on name (multiple packages can have same display name).
     */
    public function up(): void
    {
        Schema::table('system_packages', function (Blueprint $table) {
            // Add install script column - the bash script to run for installation
            $table->text('install_script')->nullable()->after('name');

            // Remove unique constraint on name - it's just a display name now
            $table->dropUnique(['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_packages', function (Blueprint $table) {
            $table->dropColumn('install_script');
            $table->unique('name');
        });
    }
};
