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
        Schema::table('ai_models', function (Blueprint $table) {
            $table->decimal('cache_write_price_per_million', 10, 4)->nullable()->after('output_price_per_million');
            $table->decimal('cache_read_price_per_million', 10, 4)->nullable()->after('cache_write_price_per_million');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn(['cache_write_price_per_million', 'cache_read_price_per_million']);
        });
    }
};
