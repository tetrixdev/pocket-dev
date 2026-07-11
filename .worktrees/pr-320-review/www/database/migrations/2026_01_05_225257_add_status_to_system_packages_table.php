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
        Schema::table('system_packages', function (Blueprint $table) {
            // Status: pending (not yet installed), installed, failed
            $table->string('status', 20)->default('pending')->after('name');
            // Message from installation (error message if failed)
            $table->text('status_message')->nullable()->after('status');
            // When the package was last installed
            $table->timestamp('installed_at')->nullable()->after('status_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_packages', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_message', 'installed_at']);
        });
    }
};
