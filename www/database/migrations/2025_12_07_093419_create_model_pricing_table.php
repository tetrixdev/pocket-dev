<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Insert default Anthropic pricing
        // Cache write = 1.25x base input, Cache read = 0.1x base input
        DB::table('model_pricing')->insert([
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-sonnet-4-5-20250929',
                'name' => 'Claude Sonnet 4.5',
                'input_price' => 3.00,
                'output_price' => 15.00,
                'cache_write_price' => 3.75,
                'cache_read_price' => 0.30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-opus-4-5-20251101',
                'name' => 'Claude Opus 4.5',
                'input_price' => 5.00,
                'output_price' => 25.00,
                'cache_write_price' => 6.25,
                'cache_read_price' => 0.50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-haiku-4-5-20251101',
                'name' => 'Claude Haiku 4.5',
                'input_price' => 1.00,
                'output_price' => 5.00,
                'cache_write_price' => 1.25,
                'cache_read_price' => 0.10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-5-sonnet-20241022',
                'name' => 'Claude 3.5 Sonnet',
                'input_price' => 3.00,
                'output_price' => 15.00,
                'cache_write_price' => 3.75,
                'cache_read_price' => 0.30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-opus-20240229',
                'name' => 'Claude 3 Opus',
                'input_price' => 15.00,
                'output_price' => 75.00,
                'cache_write_price' => 18.75,
                'cache_read_price' => 1.50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-haiku-20240307',
                'name' => 'Claude 3 Haiku',
                'input_price' => 0.25,
                'output_price' => 1.25,
                'cache_write_price' => 0.30,
                'cache_read_price' => 0.03,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_pricing');
    }
};
