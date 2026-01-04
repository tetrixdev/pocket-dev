FROM postgres:17

# Install PostGIS and pgvector extensions
# - postgresql-17-postgis-3: PostGIS 3.x for spatial queries
# - postgresql-17-pgvector: pgvector for vector similarity search
# - pg_trgm is included in postgresql-contrib (comes with base postgres image)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        postgresql-17-postgis-3 \
        postgresql-17-pgvector \
    && rm -rf /var/lib/apt/lists/*

# Copy init script to create extensions on database initialization
COPY init-extensions.sql /docker-entrypoint-initdb.d/
