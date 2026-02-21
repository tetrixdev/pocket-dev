<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pocket_tools', function (Blueprint $table) {
            $table->json('panel_dependencies')->nullable()->after('blade_template');
        });
    }

    public function down(): void
    {
        Schema::table('pocket_tools', function (Blueprint $table) {
            $table->dropColumn('panel_dependencies');
        });
    }
};
