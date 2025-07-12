#!/bin/bash

echo "🔧 Setting up Claude Code configuration..."

# Ensure .claude directory exists
mkdir -p /home/pocketdev/.claude

# Always copy the latest CLAUDE.md template
if [ -f /opt/pocketdev-templates/CLAUDE.md ]; then
    cp /opt/pocketdev-templates/CLAUDE.md /home/pocketdev/.claude/CLAUDE.md
    echo "✅ CLAUDE.md updated to latest version"
else
    echo "⚠️  CLAUDE.md template not found"
fi

# Always copy the latest settings.json template
if [ -f /opt/pocketdev-templates/settings.json ]; then
    cp /opt/pocketdev-templates/settings.json /home/pocketdev/.claude/settings.json
    echo "✅ settings.json updated to latest version"
else
    echo "⚠️  settings.json template not found"
fi