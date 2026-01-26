# =============================================================================
# SHARED BASE SECTION (identical in both local and production Dockerfiles)
# =============================================================================
FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    ca-certificates \
    gnupg \
    lsb-release \
    build-essential \
    locales \
    supervisor \
    gosu \
    && rm -rf /var/lib/apt/lists/* \
    && gosu nobody true

# Install PostgreSQL 17 client (for pg_dump/pg_restore with PG17 server)
RUN curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql-keyring.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql-keyring.gpg] http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y postgresql-client-17 \
    && rm -rf /var/lib/apt/lists/*

# Configure and generate UTF-8 locale for proper Unicode character support
RUN sed -i '/en_US.UTF-8/s/^# //g' /etc/locale.gen && \
    locale-gen en_US.UTF-8 && \
    dpkg-reconfigure --frontend=noninteractive locales

# Set environment variables for locale support
ENV LANG=en_US.UTF-8
ENV LC_ALL=en_US.UTF-8

# Install Docker CLI (for socket mounting, not Docker-in-Docker)
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Install GitHub CLI
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && apt-get update \
    && apt-get install -y gh \
    && rm -rf /var/lib/apt/lists/*

# Create groups for various access needs:
# - docker (999): Docker socket access
# - hostdocker (1001): Host Docker group compatibility
# - appgroup (1000): Cross-group ownership - www-data's primary group for file creation
# Cross-group ownership model:
# - www-data: primary group 1000 (appgroup), secondary group 33 (www-data)
# - appuser: primary group 33 (www-data), secondary group 1000 (appgroup)
# This allows bidirectional file access between www-data and appuser processes
RUN groupadd -g 999 docker || true \
    && groupadd -g 1001 hostdocker || true \
    && groupadd -g 1000 appgroup || true \
    && usermod -g 1000 www-data \
    && usermod -aG 33,docker,hostdocker www-data

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql zip

# Install Node.js 22.x LTS (latest stable)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Claude Code CLI and Codex CLI for AI-powered development
RUN npm install -g @anthropic-ai/claude-code @openai/codex

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

# Create Laravel directories with proper structure
RUN mkdir -p /var/www/storage/framework/{sessions,views,cache} \
    && mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/bootstrap/cache

# Create home directory for Claude Code CLI, Codex CLI, and other tools that need $HOME
# - Owned by UID 1000, GID 33 (www-data) with group-writable permissions (775)
# - Cross-group ownership: appuser (1000:33) owns, www-data can write via group
# - In local dev: volume mount may override this, entrypoint fixes permissions
# - In production: www-data writes directly via group membership
RUN mkdir -p /home/appuser/.claude /home/appuser/.codex \
    && chown -R 1000:33 /home/appuser \
    && chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex

# Change www-data's home directory to /home/appuser
# This ensures all CLI tools (claude, codex, git, gh, etc.) use consistent paths
# regardless of whether called from PHP-FPM, entrypoint, or docker exec
RUN usermod -d /home/appuser www-data

# Set working directory
WORKDIR /var/www

# =============================================================================
# LOCAL DEVELOPMENT SECTION (development-specific configuration)
# =============================================================================

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Development uses volumes for hot-reloading, so no need to copy files
# The entrypoint and application code are mounted via docker compose volumes