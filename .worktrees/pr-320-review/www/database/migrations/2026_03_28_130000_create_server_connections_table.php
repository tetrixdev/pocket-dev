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
        Schema::create('server_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');                         // Workspace this server belongs to
            $table->string('name', 255);                          // Friendly name, e.g., 'PROD-01'
            $table->string('host', 255);                          // IP or hostname
            $table->string('ssh_user', 64)->default('admin');     // SSH username
            $table->integer('ssh_port')->default(22);             // SSH port
            $table->text('notes')->nullable();                    // Optional notes

            // Detected software (updated by detection routine)
            $table->boolean('has_vps_setup')->nullable();         // null = not checked, true/false = detected
            $table->string('vps_setup_mode', 16)->nullable();     // 'public' or 'private'
            $table->boolean('has_proxy_nginx')->nullable();       // null = not checked, true/false = detected
            $table->string('proxy_nginx_version', 32)->nullable(); // e.g., '1.0.0'
            $table->boolean('has_tailscale')->nullable();         // null = not checked
            $table->string('tailscale_ip', 45)->nullable();       // Tailscale IP if detected

            $table->timestamp('last_checked_at')->nullable();     // Last detection run
            $table->timestamp('last_connection_at')->nullable();  // Last successful SSH connection
            $table->string('last_connection_error')->nullable();  // Last connection error message

            $table->timestamps();

            // Indexes
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->unique(['workspace_id', 'host']);  // Host unique per workspace
            $table->index('workspace_id');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_connections');
    }
};
