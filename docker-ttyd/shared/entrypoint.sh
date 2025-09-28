#!/bin/bash
set -e

echo "🚀 Configuring development environment..."

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "⚙️  Configuring git credentials..."

    # Configure git user information
    git config --global user.name "$GIT_USER_NAME"
    git config --global user.email "$GIT_USER_EMAIL"

    # Configure git credential helper for HTTPS repos
    git config --global credential.helper store

    # Store GitHub credentials in standard format (username = "token" for GitHub tokens)
    echo "https://token:$GIT_TOKEN@github.com" > ~/.git-credentials
    chmod 600 ~/.git-credentials

    echo "✅ Git and GitHub CLI configured for user: $GIT_USER_NAME"
    echo "   GitHub CLI will use GH_TOKEN environment variable"
else
    echo "ℹ️  Git credentials not provided - skipping git/GitHub CLI setup"
    echo "   Set GIT_TOKEN, GIT_USER_NAME, and GIT_USER_EMAIL to enable"
fi

# Set workspace permissions
if [ -d "/workspace" ]; then
    echo "⚙️  Setting workspace permissions..."
    sudo chown -R $(id -u):$(id -g) /workspace 2>/dev/null || true
fi

echo "🎯 Starting ttyd terminal server..."

# Start ttyd with authentication support
exec ttyd \
    --port 7681 \
    --writable \
    --max-clients 10 \
    --once \
    --cwd /workspace \
    bash