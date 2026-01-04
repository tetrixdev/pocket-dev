FROM nginx:alpine

# Install apache2-utils for htpasswd command, certbot for SSL certificates
RUN apk add --no-cache apache2-utils gettext certbot certbot-nginx

# Copy nginx configuration template
COPY docker-proxy/shared/nginx.conf.template /etc/nginx/nginx.conf.template

# Copy nginx include templates
COPY docker-proxy/shared/includes/ /etc/nginx/includes/

# Copy entrypoint script
COPY docker-proxy/shared/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose port
EXPOSE 80

# Use custom entrypoint
ENTRYPOINT ["/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]