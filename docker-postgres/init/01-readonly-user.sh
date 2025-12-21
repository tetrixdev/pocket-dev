#!/bin/bash
# Create a read-only user for AI tool queries
# This user has SELECT access to ALL tables via ALTER DEFAULT PRIVILEGES
#
# Password is read from environment variable DB_READONLY_PASSWORD
# Falls back to 'readonly_password' for development convenience
#
# TODO: Consider restricting SELECT to specific tables (memory_*, agents, tools)
#       instead of ALL tables if multi-user or sensitive data concerns arise.
#       Currently grants access to settings table which contains API keys.
#       API keys in settings table are encrypted via Laravel's `encrypted` cast,
#       so SQL access only yields encrypted values. Consider table-level
#       restrictions in future if multi-user support is added.

set -e

if [ -z "$DB_READONLY_PASSWORD" ]; then
    echo "ERROR: DB_READONLY_PASSWORD environment variable is required"
    exit 1
fi
READONLY_PASSWORD="$DB_READONLY_PASSWORD"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    -- Create the readonly role
    CREATE USER memory_readonly WITH PASSWORD '${READONLY_PASSWORD}';

    -- Grant connect to the database
    GRANT CONNECT ON DATABASE "$POSTGRES_DB" TO memory_readonly;

    -- Grant usage on public schema
    GRANT USAGE ON SCHEMA public TO memory_readonly;

    -- Set default privileges so new tables in public schema get SELECT for readonly user
    -- This grants SELECT on ALL tables, which is intentional for AI tool access
    -- (tools, agents, memory_*, etc. - but NOT write access)
    ALTER DEFAULT PRIVILEGES IN SCHEMA public
        GRANT SELECT ON TABLES TO memory_readonly;

    -- Create a function to grant SELECT on memory tables (called after migrations)
    -- This is kept for backwards compatibility and explicit grants
    CREATE OR REPLACE FUNCTION grant_memory_readonly() RETURNS void AS \$\$
    DECLARE
        grant_sql TEXT;
    BEGIN
        -- Grant SELECT on all memory_* tables
        SELECT string_agg('GRANT SELECT ON ' || quote_ident(tablename) || ' TO memory_readonly', '; ')
        INTO grant_sql
        FROM pg_tables
        WHERE schemaname = 'public'
          AND tablename LIKE 'memory_%';

        IF grant_sql IS NOT NULL THEN
            EXECUTE grant_sql;
        END IF;
    END;
    \$\$ LANGUAGE plpgsql;
EOSQL

echo "Created memory_readonly user with SELECT privileges"
