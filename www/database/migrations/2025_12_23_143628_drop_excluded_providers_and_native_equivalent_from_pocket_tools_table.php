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
            $table->dropColumn(['excluded_providers', 'native_equivalent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pocket_tools', function (Blueprint $table) {
            $table->json('excluded_providers')->nullable()->after('category');
            $table->string('native_equivalent')->nullable()->after('excluded_providers');
        });
    }
};
