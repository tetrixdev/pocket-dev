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
# - appgroup (1000): Shared group for home directory access (Claude CLI, git, etc.)
# Add www-data to all groups so it can access docker socket and write to /home/appuser
RUN groupadd -g 999 docker || true \
    && groupadd -g 1001 hostdocker || true \
    && groupadd -g 1000 appgroup || true \
    && usermod -aG docker,hostdocker,appgroup www-data

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
# - Owned by UID 1000, GID 1000 (appgroup) with group-writable permissions (775)
# - www-data is in group 1000 (appgroup), so it can write here
# - In local dev: volume mount may override this, group_add handles permissions
# - In production: www-data writes directly via group membership
RUN mkdir -p /home/appuser/.claude /home/appuser/.codex \
    && chown -R 1000:1000 /home/appuser \
    && chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex

# Change www-data's home directory to /home/appuser
# This ensures all CLI tools (claude, codex, git, gh, etc.) use consistent paths
# regardless of whether called from PHP-FPM, entrypoint, or docker exec
RUN usermod -d /home/appuser www-data

# Set working directory
WORKDIR /var/www

# =============================================================================
# PRODUCTION SECTION (production-specific configuration and optimization)
# =============================================================================

# Copy Laravel application
COPY www/ /var/www/

# Install Composer dependencies (production optimized)
RUN composer install --no-dev --optimize-autoloader --no-interaction --verbose

# Install npm dependencies (including dev deps for build), build assets, then clean up
RUN npm ci \
    && npm run build \
    && rm -rf node_modules

# Create storage symlink
RUN php artisan storage:link --no-interaction

# Copy PHP configuration
COPY docker-laravel/shared/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Copy supervisor configuration for queue workers
COPY docker-laravel/shared/supervisor/queue-workers.conf /etc/supervisor/conf.d/queue-workers.conf

# Copy production entrypoints
COPY docker-laravel/production/php/entrypoint.sh /entrypoint.sh
COPY docker-laravel/production/php/queue-entrypoint.sh /queue-entrypoint.sh
RUN chmod +x /entrypoint.sh /queue-entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www

# NOTE: No USER directive - containers start as root, then drop privileges via gosu
# - PHP-FPM container: runs as root (master), workers drop to www-data via pool config
# - Queue container: gosu www-data in queue-entrypoint.sh
# This allows privileged operations (apt-get for system packages) before dropping privileges

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Use production entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]