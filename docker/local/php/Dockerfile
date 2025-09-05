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
    && rm -rf /var/lib/apt/lists/*

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
# LOCAL DEVELOPMENT SECTION (development-specific configuration)
# =============================================================================

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Development uses volumes for hot-reloading, so no need to copy files
# The entrypoint and application code are mounted via docker-compose volumes