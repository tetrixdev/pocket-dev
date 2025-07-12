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