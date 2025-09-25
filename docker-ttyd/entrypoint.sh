#!/bin/bash
set -e

# Custom git configuration example:
# git config --global user.name "Your Name"
# git config --global user.email "your.email@domain.com"

# Start ttyd with authentication support
# The token parameter will be passed from the compose environment
exec ttyd \
    --port 7681 \
    --writable \
    --max-clients 5 \
    --once \
    --cwd /workspace \
    bash