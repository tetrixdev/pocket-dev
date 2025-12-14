#!/bin/sh
set -e

# =============================================================================
# DEPLOYMENT MODE CONFIGURATION
# =============================================================================
DEPLOYMENT_MODE=${DEPLOYMENT_MODE:-local}
DOMAIN_NAME=${DOMAIN_NAME:-localhost}

echo "🚀 Configuring pocket-dev proxy..."
echo "   - Deployment mode: $DEPLOYMENT_MODE"
echo "   - Domain name: $DOMAIN_NAME"

# =============================================================================
# BASIC AUTHENTICATION SETUP
# =============================================================================
# Default values
IP_ALLOWED="1"

# Skip basic auth in local mode
if [ "$DEPLOYMENT_MODE" = "local" ]; then
    echo "🏠 Local mode: Basic authentication disabled"
    # Create empty htpasswd file (nginx requires it to exist)
    touch /etc/nginx/.htpasswd
    AUTH_ENABLED="off"
else
    # Basic authentication is REQUIRED for production
    if [ -z "$BASIC_AUTH_USER" ] || [ -z "$BASIC_AUTH_PASS" ]; then
        echo "❌ ERROR: Basic authentication credentials are required!"
        echo "   Please set BASIC_AUTH_USER and BASIC_AUTH_PASS in your .env file"
        echo "   Example:"
        echo "     BASIC_AUTH_USER=admin"
        echo "     BASIC_AUTH_PASS=your_secure_password"
        exit 1
    fi

    # Check if using insecure default password (production check)
    if [ "$BASIC_AUTH_PASS" = "CHANGE_BASIC_AUTH_PASS" ]; then
        echo "❌ ERROR: You must change the default BASIC_AUTH_PASS value!"
        echo "   Current value: $BASIC_AUTH_PASS"
        echo "   Please set a secure password in your .env file"
        exit 1
    fi

    echo "🔐 Setting up basic authentication..."
    htpasswd -cb /etc/nginx/.htpasswd "$BASIC_AUTH_USER" "$BASIC_AUTH_PASS"
    AUTH_ENABLED="Secure Access"
    echo "✅ Basic authentication enabled for user: $BASIC_AUTH_USER"
fi

# Setup IP whitelist if provided
if [ -n "$IP_WHITELIST" ]; then
    echo "Setting up IP whitelist: $IP_WHITELIST"

    # Create nginx map for IP whitelist
    WHITELIST_MAP=""
    IFS=','
    for ip in $IP_WHITELIST; do
        # Remove whitespace
        ip=$(echo "$ip" | xargs)
        WHITELIST_MAP="$WHITELIST_MAP~$ip 1;"
    done

    # Create a map block in nginx config
    cat > /etc/nginx/conf.d/ip_whitelist.conf << EOF
map \$remote_addr \$ip_whitelisted {
    default 0;
    $WHITELIST_MAP
}
EOF

    # Update IP_ALLOWED to check the map
    IP_ALLOWED="\$ip_whitelisted"
    echo "✅ IP whitelist configured"
else
    echo "ℹ️  IP whitelist disabled (all IPs allowed)"
fi

# =============================================================================
# NGINX CONFIGURATION GENERATION
# =============================================================================

# Copy upstream settings template to conf.d
echo "📝 Copying upstream settings..."
cp /etc/nginx/includes/00-upstream-settings.conf.template /etc/nginx/conf.d/00-upstream-settings.conf

# Configure deployment-specific settings
if [ "$DEPLOYMENT_MODE" = "production" ]; then
    echo "🔒 Production mode: Enabling IP blocking for direct IP access"
    cp /etc/nginx/includes/01-default-server-production.conf /etc/nginx/conf.d/01-default-server.conf
    DEFAULT_SERVER=""  # Main server is NOT default_server (IP blocking server is)
else
    echo "🏠 Local mode: No IP blocking"
    cp /etc/nginx/includes/01-default-server-local.conf /etc/nginx/conf.d/01-default-server.conf
    DEFAULT_SERVER=" default_server"  # Main server IS default_server
fi

# Initialize proxy config from default template if it doesn't exist
if [ ! -f "/etc/nginx-proxy-config/nginx.conf.template" ]; then
    echo "📝 Initializing nginx config from default template..."
    cp /etc/nginx/nginx.conf.template /etc/nginx-proxy-config/nginx.conf.template
    echo "✅ Default template copied to proxy config volume"
fi

# Process nginx configuration template from proxy config volume
export AUTH_ENABLED
export IP_ALLOWED
export DOMAIN_NAME
export DEFAULT_SERVER

envsubst '${AUTH_ENABLED} ${IP_ALLOWED} ${DOMAIN_NAME} ${DEFAULT_SERVER}' < /etc/nginx-proxy-config/nginx.conf.template > /etc/nginx/nginx.conf

echo "🚀 Proxy configuration complete"
echo "   - IP Filter: $([ -n "$IP_WHITELIST" ] && echo "enabled" || echo "disabled")"
echo "   - IP Blocking: $([ "$DEPLOYMENT_MODE" = "production" ] && echo "enabled" || echo "disabled")"

# Execute the main command
exec "$@"