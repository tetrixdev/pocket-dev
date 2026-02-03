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
        Schema::table('pocket_tools', function (Blueprint $table) {
            // Tool type: 'script' for traditional tools, 'panel' for interactive panels
            $table->string('type')->default('script')->after('category');

            // Blade template for panel rendering (only used when type='panel')
            $table->text('blade_template')->nullable()->after('script');

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pocket_tools', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'blade_template']);
        });
    }
};
