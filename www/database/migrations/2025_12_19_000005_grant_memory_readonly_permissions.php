<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grant SELECT permissions to the memory_readonly user on all memory tables.
     * This ensures the read-only database connection can only read from these tables.
     */
    public function up(): void
    {
        // Only run on PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Check if the readonly user exists (created by docker-postgres/init script)
        $userExists = DB::selectOne(
            "SELECT 1 FROM pg_roles WHERE rolname = 'memory_readonly'"
        );

        if (!$userExists) {
            // User doesn't exist - likely running outside Docker or fresh setup
            // Create the user here as fallback
            $password = config('database.connections.pgsql_readonly.password');

            if (empty($password)) {
                throw new \RuntimeException('DB_READONLY_PASSWORD environment variable must be set for memory_readonly user');
            }

            $database = config('database.connections.pgsql.database');
            DB::statement("CREATE USER memory_readonly WITH PASSWORD " . DB::connection()->getPdo()->quote($password));
            DB::statement("GRANT CONNECT ON DATABASE " . DB::connection()->getPdo()->quote($database) . " TO memory_readonly");
            DB::statement("GRANT USAGE ON SCHEMA public TO memory_readonly");
        }

        // Grant SELECT on all memory tables
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
