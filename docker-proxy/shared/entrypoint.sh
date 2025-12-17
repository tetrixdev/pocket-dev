#!/bin/sh
set -e

# =============================================================================
# DEPLOYMENT MODE CONFIGURATION
# =============================================================================
DEPLOYMENT_MODE=${DEPLOYMENT_MODE:-local}
DOMAIN_NAME=${DOMAIN_NAME:-localhost}

echo "Configuring pocket-dev proxy..."
echo "   - Deployment mode: $DEPLOYMENT_MODE"
echo "   - Domain name: $DOMAIN_NAME"

# =============================================================================
# SECURITY CONFIGURATION
# =============================================================================
# Basic auth is disabled - security should be handled by external proxy if needed
touch /etc/nginx/.htpasswd
AUTH_ENABLED="off"
IP_ALLOWED="1"

echo "   - Basic auth: disabled (use external proxy for production security)"

# =============================================================================
# NGINX CONFIGURATION GENERATION
# =============================================================================

# Copy upstream settings template to conf.d
echo "Copying upstream settings..."
cp /etc/nginx/includes/00-upstream-settings.conf.template /etc/nginx/conf.d/00-upstream-settings.conf

# Configure deployment-specific settings
if [ "$DEPLOYMENT_MODE" = "production" ]; then
    echo "Production mode: Enabling IP blocking for direct IP access"
    cp /etc/nginx/includes/01-default-server-production.conf /etc/nginx/conf.d/01-default-server.conf
    DEFAULT_SERVER=""  # Main server is NOT default_server (IP blocking server is)
else
    echo "Local mode: No IP blocking"
    cp /etc/nginx/includes/01-default-server-local.conf /etc/nginx/conf.d/01-default-server.conf
    DEFAULT_SERVER=" default_server"  # Main server IS default_server
fi

# Determine template source:
# - Use volume-mounted custom template if available
# - Otherwise use the built-in default template
if [ -d "/etc/nginx-proxy-config" ] && [ -w "/etc/nginx-proxy-config" ]; then
    # Volume is mounted and writable - use it for customization
    if [ ! -f "/etc/nginx-proxy-config/nginx.conf.template" ]; then
        echo "Initializing nginx config from default template..."
        cp /etc/nginx/nginx.conf.template /etc/nginx-proxy-config/nginx.conf.template
        echo "Default template copied to proxy config volume"
    fi
    TEMPLATE_FILE="/etc/nginx-proxy-config/nginx.conf.template"
else
    # No volume mounted - use built-in template directly
    TEMPLATE_FILE="/etc/nginx/nginx.conf.template"
fi

# Process nginx configuration template
export AUTH_ENABLED
export IP_ALLOWED
export DOMAIN_NAME
export DEFAULT_SERVER

envsubst '${AUTH_ENABLED} ${IP_ALLOWED} ${DOMAIN_NAME} ${DEFAULT_SERVER}' < "$TEMPLATE_FILE" > /etc/nginx/nginx.conf

echo "Proxy configuration complete"

# Execute the main command
exec "$@"
