<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Cost in dollars, calculated server-side from model pricing
            // Stored to avoid recalculation and maintain historical accuracy
            $table->decimal('cost', 10, 6)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
