#!/bin/bash

echo "🔧 Configuring Docker permissions..."

if [ -S /var/run/docker.sock ]; then
    # Get the group ID that owns the docker socket
    SOCKET_GID=$(stat -c '%g' /var/run/docker.sock)
    
    # Add pocketdev user to that group
    sudo usermod -a -G $SOCKET_GID pocketdev
    
    echo "✅ Added pocketdev user to group $SOCKET_GID for Docker access"
    echo "ℹ️  You may need to restart your shell or re-attach to see Docker access"
else
    echo "⚠️  Docker socket not found - Docker commands won't work"
fi