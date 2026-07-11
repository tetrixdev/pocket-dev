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
            $table->dropIndex('pocket_tools_capability_index');
            $table->dropColumn('capability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pocket_tools', function (Blueprint $table) {
            $table->string('capability')->nullable()->after('category');
            $table->index('capability');
        });
    }
};
