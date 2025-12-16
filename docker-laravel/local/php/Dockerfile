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

# Create home directory for Claude Code CLI and other tools that need $HOME
# - Owned by UID 1000, GID 1000 (appgroup) with group-writable permissions (775)
# - www-data is in group 1000 (appgroup), so it can write here
# - In local dev: volume mount may override this, group_add handles permissions
# - In production: www-data writes directly via group membership
RUN mkdir -p /home/appuser/.claude \
    && chown -R 1000:1000 /home/appuser \
    && chmod 775 /home/appuser /home/appuser/.claude

# Set working directory
WORKDIR /var/www

# =============================================================================
# LOCAL DEVELOPMENT SECTION (development-specific configuration)
# =============================================================================

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Development uses volumes for hot-reloading, so no need to copy files
# The entrypoint and application code are mounted via docker compose volumes