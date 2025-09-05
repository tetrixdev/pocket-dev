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
# PRODUCTION SECTION (production-specific configuration and optimization)
# =============================================================================

# Copy Laravel application
COPY www/ /var/www/

# Install Composer dependencies (production optimized)
RUN composer install --no-dev --optimize-autoloader --no-interaction --verbose

# Install npm dependencies and build assets
RUN npm ci --only=production \
    && npm run build \
    && rm -rf node_modules

# Create storage symlink
RUN php artisan storage:link --no-interaction

# Copy PHP configuration
COPY docker/shared/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Copy production entrypoint
COPY docker/production/php/entrypoint.sh /entrypoint.sh
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