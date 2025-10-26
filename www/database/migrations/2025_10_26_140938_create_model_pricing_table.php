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
        Schema::create('model_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('model_name')->unique();
            $table->decimal('input_price_per_million', 10, 6)->nullable();
            $table->decimal('cache_write_multiplier', 5, 3)->default(1.25);
            $table->decimal('cache_read_multiplier', 5, 3)->default(0.1);
            $table->decimal('output_price_per_million', 10, 6)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_pricing');
    }
};
