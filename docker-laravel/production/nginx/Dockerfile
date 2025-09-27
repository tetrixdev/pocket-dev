FROM nginx:1.29-alpine

# Copy processed nginx configuration
COPY docker-laravel/shared/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy Laravel public directory from PHP container build context
COPY www/public /var/www/public

# Ensure proper permissions
RUN chown -R nginx:nginx /var/www/public \
    && chmod -R 755 /var/www/public

# Remove default nginx configuration
RUN rm -f /etc/nginx/conf.d/default.conf.bak

# Expose port 80
EXPOSE 80

# Start nginx
CMD ["nginx", "-g", "daemon off;"]