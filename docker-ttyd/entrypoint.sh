#!/bin/bash
set -e

# =============================================================================
# GENERIC CONFIG START - Runtime configuration for all users
# =============================================================================

# Set up environment variables
export HOME=/home/devuser
export USER=devuser
export SHELL=/bin/bash

# Basic aliases that everyone finds useful
echo 'alias ll="ls -la"' >> ~/.bashrc
echo 'alias la="ls -A"' >> ~/.bashrc
echo 'alias l="ls -CF"' >> ~/.bashrc
echo 'alias ..="cd .."' >> ~/.bashrc
echo 'alias ...="cd ../.."' >> ~/.bashrc

# Set up Git safe directory (for workspace mounted volumes)
git config --global --add safe.directory /workspace
git config --global --add safe.directory '*'

# Set up Docker group permissions if socket is mounted
if [ -S /var/run/docker.sock ]; then
    sudo chown root:docker /var/run/docker.sock
    sudo chmod 664 /var/run/docker.sock
fi

# Set proper workspace permissions
sudo chown -R devuser:devuser /workspace 2>/dev/null || true

# =============================================================================
# GENERIC CONFIG END
# =============================================================================

# =============================================================================
# CUSTOM DEVELOPER CONFIG START - Add your personal customizations here
# =============================================================================

# Example custom aliases and functions (fork and customize):
# echo 'alias deploy="./scripts/deploy.sh"' >> ~/.bashrc
# echo 'alias logs="docker compose logs -f"' >> ~/.bashrc
# echo 'alias art="php artisan"' >> ~/.bashrc

# Custom git configuration example:
# git config --global user.name "Your Name"
# git config --global user.email "your.email@domain.com"

# Custom environment variables example:
# export EDITOR=nano
# export TERM=xterm-256color

# =============================================================================
# CUSTOM DEVELOPER CONFIG END
# =============================================================================

# Source the updated bashrc
source ~/.bashrc

# Start ttyd with authentication support
# The token parameter will be passed from the compose environment
exec ttyd \
    --port 7681 \
    --writable \
    --max-clients 5 \
    --once \
    --cwd /workspace \
    bash