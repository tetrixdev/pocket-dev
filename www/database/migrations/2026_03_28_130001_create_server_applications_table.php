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
        Schema::create('server_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');                         // Workspace this app belongs to
            $table->uuid('server_connection_id');                 // Server this app is deployed on

            $table->string('name', 255);                          // App name, e.g., 'simplebudget'
            $table->string('slug', 255);                          // URL-safe slug
            $table->text('description')->nullable();              // Optional description

            // Deployment configuration
            $table->string('deploy_path', 512);                   // Path on server, e.g., '/home/admin/docker-apps/simplebudget'
            $table->text('compose_content')->nullable();          // compose.yml content
            $table->text('env_content')->nullable();              // .env content (encrypted)

            // Domain configuration (for proxy-nginx)
            $table->json('domains')->nullable();                  // Array of domains, e.g., ["www.example.com", "example.com"]
            $table->string('upstream_container', 255)->nullable(); // Container name for proxy, e.g., 'simplebudget-nginx'
            $table->boolean('ssl_enabled')->default(false);       // Whether SSL is configured
            $table->timestamp('ssl_expires_at')->nullable();      // SSL certificate expiry

            // Status
            $table->string('status', 32)->default('stopped');     // stopped, running, error, deploying
            $table->text('last_error')->nullable();               // Last error message
            $table->timestamp('last_deployed_at')->nullable();    // Last successful deployment

            $table->timestamps();

            // Foreign keys
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('server_connection_id')->references('id')->on('server_connections')->onDelete('cascade');

            // Indexes
            $table->unique(['server_connection_id', 'slug']);     // Slug unique per server
            $table->index('workspace_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_applications');
    }
};
