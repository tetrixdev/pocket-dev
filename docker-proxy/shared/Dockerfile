FROM nginx:alpine

# Install apache2-utils for htpasswd command
RUN apk add --no-cache apache2-utils envsubst

# Copy nginx configuration template
COPY docker-proxy/shared/nginx.conf.template /etc/nginx/nginx.conf.template

# Copy entrypoint script
COPY docker-proxy/shared/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose port
EXPOSE 80

# Use custom entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]