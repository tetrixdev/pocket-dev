<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->integer('turn_number')->nullable()->index();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->integer('last_embedded_turn_number')->default(-1);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('turn_number');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('last_embedded_turn_number');
        });
    }
};
