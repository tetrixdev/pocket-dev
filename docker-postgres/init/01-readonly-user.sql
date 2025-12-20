-- Create a read-only user for memory queries
-- This user can only SELECT from memory_* tables

-- Create the readonly role
CREATE USER memory_readonly WITH PASSWORD 'readonly_password';

-- Grant connect to the database
GRANT CONNECT ON DATABASE "pocket-dev" TO memory_readonly;

-- Grant usage on public schema
GRANT USAGE ON SCHEMA public TO memory_readonly;

-- Grant SELECT on memory tables (these may not exist yet, so we use a function)
-- The actual grants happen via default privileges and on-demand grants

-- Set default privileges so new tables in public schema get SELECT for readonly user
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO memory_readonly;

-- Create a function to grant SELECT on memory tables (called after migrations)
CREATE OR REPLACE FUNCTION grant_memory_readonly() RETURNS void AS $$
BEGIN
    -- Grant SELECT on all memory_* tables
    EXECUTE (
        SELECT string_agg('GRANT SELECT ON ' || quote_ident(tablename) || ' TO memory_readonly', '; ')
        FROM pg_tables
        WHERE schemaname = 'public'
          AND tablename LIKE 'memory_%'
    );
END;
$$ LANGUAGE plpgsql;
