<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the memory_readonly user and grant SELECT permissions on memory tables.
     * This user provides read-only database access for the MemoryQueryTool.
     */
    public function up(): void
    {
        // Only run on PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Create the readonly user if it doesn't exist (idempotent)
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_readonly'"
        );

        if (!$userExists) {
            $password = config('database.connections.pgsql_readonly.password');

            if (empty($password)) {
                throw new \RuntimeException('DB_READONLY_PASSWORD must be set in .env file for memory_readonly user');
            }

            // Create user with password (PDO::quote handles string literal escaping)
            DB::statement("CREATE USER memory_readonly WITH PASSWORD " . DB::connection()->getPdo()->quote($password));

            // Grant database connection (use double quotes for identifier with special chars)
            $database = config('database.connections.pgsql.database');
            $quotedDatabase = '"' . str_replace('"', '""', $database) . '"';
            DB::statement("GRANT CONNECT ON DATABASE {$quotedDatabase} TO memory_readonly");

            DB::statement("GRANT USAGE ON SCHEMA public TO memory_readonly");
        }

        // Grant SELECT on memory tables
        $memoryTables = ['memory_structures', 'memory_objects', 'memory_embeddings'];

        foreach ($memoryTables as $table) {
            DB::statement("GRANT SELECT ON {$table} TO memory_readonly");
        }
    }

    /**
     * Revoke permissions (though the user should remain for future use).
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_readonly'"
        );

        if (!$userExists) {
            return;
        }

        $memoryTables = ['memory_structures', 'memory_objects', 'memory_embeddings'];

        foreach ($memoryTables as $table) {
            DB::statement("REVOKE SELECT ON {$table} FROM memory_readonly");
        }
    }
};
