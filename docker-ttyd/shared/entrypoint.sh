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

# Initialize default configuration files if they don't exist
echo "⚙️  Initializing configuration files..."

# Copy default CLAUDE.md if it doesn't exist
if [ ! -f "/home/devuser/.claude/CLAUDE.md" ]; then
    echo "📝 Creating default CLAUDE.md..."
    mkdir -p /home/devuser/.claude
    cp /defaults/CLAUDE.md /home/devuser/.claude/CLAUDE.md
    echo "✅ CLAUDE.md initialized"
fi

# Copy default TROUBLESHOOTING.md if it doesn't exist
if [ ! -f "/home/devuser/.claude/TROUBLESHOOTING.md" ]; then
    echo "📝 Creating default TROUBLESHOOTING.md..."
    mkdir -p /home/devuser/.claude
    cp /defaults/TROUBLESHOOTING.md /home/devuser/.claude/TROUBLESHOOTING.md
    echo "✅ TROUBLESHOOTING.md initialized"
fi

# Copy default settings.json if it doesn't exist
if [ ! -f "/home/devuser/.claude/settings.json" ]; then
    echo "⚙️  Creating default settings.json..."
    mkdir -p /home/devuser/.claude
    cp /defaults/settings.json /home/devuser/.claude/settings.json
    echo "✅ settings.json initialized"
fi

# Copy default .tmux.conf if it doesn't exist
if [ ! -f "/home/devuser/.tmux.conf" ]; then
    echo "⚙️  Creating default .tmux.conf..."
    cp /defaults/.tmux.conf /home/devuser/.tmux.conf
    echo "✅ .tmux.conf initialized (mouse scrolling enabled)"
fi

# Copy agents directory if it doesn't exist
if [ ! -d "/home/devuser/.claude/agents" ]; then
    echo "⚙️  Creating default agents..."
    mkdir -p /home/devuser/.claude/agents
    cp -r /defaults/agents/* /home/devuser/.claude/agents/
    echo "✅ Agents initialized"
fi

echo "🎯 Starting ttyd terminal server..."

# Start ttyd with authentication support and persistent tmux session
# -u flag forces UTF-8 mode for proper character rendering (rounded corners, etc.)
exec ttyd \
    --port 7681 \
    --writable \
    --max-clients 10 \
    --cwd /workspace \
    tmux -u new-session -A -s main