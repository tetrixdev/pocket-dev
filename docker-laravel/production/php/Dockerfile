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

# Copy production entrypoint
COPY docker-laravel/production/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www

# Switch to www-data user for security
USER www-data

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Use production entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]