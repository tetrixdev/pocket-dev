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
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI (for socket mounting, not Docker-in-Docker)
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Create host docker group (GID 1001) and file access group for volumes
# Add www-data to both groups for docker socket and mounted volume access
RUN groupadd -g 999 docker || true \
    && groupadd -g 1001 hostdocker || true \
    && usermod -aG docker,hostdocker www-data

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql zip

# Install Node.js 22.x LTS (latest stable)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Claude Code CLI for AI-powered development
RUN npm install -g @anthropic-ai/claude-code

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

# Create Laravel directories with proper structure
RUN mkdir -p /var/www/storage/framework/{sessions,views,cache} \
    && mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/bootstrap/cache

# Set working directory
WORKDIR /var/www

# =============================================================================
# LOCAL DEVELOPMENT SECTION (development-specific configuration)
# =============================================================================

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Development uses volumes for hot-reloading, so no need to copy files
# The entrypoint and application code are mounted via docker compose volumes