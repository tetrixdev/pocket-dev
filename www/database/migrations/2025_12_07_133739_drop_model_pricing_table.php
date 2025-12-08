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
        Schema::dropIfExists('model_pricing');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Old table structure - recreate if needed
        Schema::create('model_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model_id', 100);
            $table->string('name', 100);
            $table->decimal('input_price', 10, 4)->comment('Price per million input tokens');
            $table->decimal('output_price', 10, 4)->comment('Price per million output tokens');
            $table->decimal('cache_write_price', 10, 4)->comment('Price per million cache write tokens');
            $table->decimal('cache_read_price', 10, 4)->comment('Price per million cache read tokens');
            $table->timestamps();

            $table->unique(['provider', 'model_id']);
        });
    }
};
