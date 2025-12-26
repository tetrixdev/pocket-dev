<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the memory_ai database user with DDL/DML rights on memory schema.
     */
    public function up(): void
    {
        $password = env('DB_MEMORY_AI_PASSWORD');

        // Check if user already exists
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_ai'"
        );

        if (!$userExists) {
            if (empty($password)) {
                throw new \RuntimeException('DB_MEMORY_AI_PASSWORD must be set in .env file for memory_ai user');
            }

            // Create the user
            DB::statement("CREATE USER memory_ai WITH PASSWORD " . DB::connection()->getPdo()->quote($password));
        }

        // Grant schema access
        DB::statement('GRANT USAGE ON SCHEMA memory TO memory_ai');
        DB::statement('GRANT CREATE ON SCHEMA memory TO memory_ai');

        // Grant full access to all current and future tables in memory schema
        DB::statement('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA memory TO memory_ai');
        DB::statement('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA memory TO memory_ai');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA memory GRANT ALL PRIVILEGES ON TABLES TO memory_ai');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA memory GRANT ALL PRIVILEGES ON SEQUENCES TO memory_ai');

        // Protect embeddings table - revoke destructive operations
        DB::statement('REVOKE DELETE, TRUNCATE ON memory.embeddings FROM memory_ai');

        // Protect schema_registry table - revoke destructive operations
        DB::statement('REVOKE DELETE, TRUNCATE ON memory.schema_registry FROM memory_ai');

        // Ensure no access to public schema
        DB::statement('REVOKE ALL ON SCHEMA public FROM memory_ai');

        // Grant connect to database
        $database = DB::connection()->getDatabaseName();
        $quotedDatabase = '"' . str_replace('"', '""', $database) . '"';
        DB::statement("GRANT CONNECT ON DATABASE {$quotedDatabase} TO memory_ai");
    }

    /**
     * Remove permissions and optionally drop the user.
     */
    public function down(): void
    {
        // Check if user exists
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_ai'"
        );

        if ($userExists) {
            // Revoke all privileges
            DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA memory FROM memory_ai');
            DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA memory FROM memory_ai');
            DB::statement('REVOKE ALL ON SCHEMA memory FROM memory_ai');

            $database = DB::connection()->getDatabaseName();
            $quotedDatabase = '"' . str_replace('"', '""', $database) . '"';
            DB::statement("REVOKE CONNECT ON DATABASE {$quotedDatabase} FROM memory_ai");

            // Drop the user
            DB::statement('DROP USER IF EXISTS memory_ai');
        }
    }
};
