-- Initialize PostgreSQL extensions for PocketDev
-- This script runs automatically when the database is first created

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS postgis;
