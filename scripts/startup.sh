#!/bin/bash

# Run permission check
check-permissions

# If check failed, exit
if [ $? -ne 0 ]; then
    echo "❌ Setup failed, exiting..."
    exit 1
fi

# Fix Docker permissions
fix-docker-permissions

# Setup Claude configuration
setup-claude-config

# Start web services
echo "🚀 Starting web services..."

# Start PHP-FPM
echo "🐘 Starting PHP-FPM..."
if sudo service php8.4-fpm start; then
    echo "✅ PHP-FPM started successfully"
else
    echo "❌ PHP-FPM failed to start"
fi
sleep 1

# Start Nginx
echo "🌐 Starting Nginx..."
if sudo service nginx start; then
    echo "✅ Nginx started successfully"
else
    echo "❌ Nginx failed to start"
fi
sleep 1

# Start TTYD for web-based terminal
echo "💻 Starting web-based terminal (TTYD)..."
sudo -u pocketdev ttyd -W -i 0.0.0.0 -p 7681 bash &
sleep 3

# Check if TTYD is running and listening
if pgrep ttyd > /dev/null; then
    echo "✅ TTYD process started successfully"
    if netstat -tuln | grep :7681 > /dev/null 2>&1; then
        echo "✅ TTYD is listening on port 7681"
    else
        echo "❌ TTYD not listening on port 7681"
    fi
else
    echo "❌ TTYD process failed to start"
fi

echo "✅ All web services startup completed!"
echo "🌍 PocketDev web interface available at http://localhost"
echo "💻 Web terminal available at http://localhost/terminal"

# Show OpenAI API key status for voice features
if [ -n "$OPENAI_API_KEY" ]; then
    echo "🎤 Voice features enabled (OpenAI API key provided)"
else
    echo "🎤 Voice features available (OpenAI API key will be requested in browser)"
fi

# If we're in interactive mode (has TTY), start bash with proper groups
if [ -t 0 ]; then
    # Get the docker socket group ID to start bash with correct permissions
    if [ -S /var/run/docker.sock ]; then
        SOCKET_GID=$(stat -c '%g' /var/run/docker.sock)
        SOCKET_GROUP=$(getent group $SOCKET_GID | cut -d: -f1)
        if [ -n "$SOCKET_GROUP" ]; then
            exec sg $SOCKET_GROUP -c "/bin/bash"
        else
            exec /bin/bash
        fi
    else
        exec /bin/bash
    fi
else
    # In detached mode, keep container alive
    exec sleep infinity
fi