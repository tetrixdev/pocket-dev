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
            // CLI command(s) to invoke - this is what appears in the AI system prompt
            // e.g., "mgc" for Microsoft Graph CLI, "libreoffice, soffice" for LibreOffice
            $table->string('cli_commands')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_packages', function (Blueprint $table) {
            $table->dropColumn('cli_commands');
        });
    }
};
