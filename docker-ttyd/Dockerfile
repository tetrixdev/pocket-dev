FROM ubuntu:24.04

# =============================================================================
# GENERIC INSTALLATION START - Base development environment for all users
# =============================================================================

# Set environment variables for non-interactive installation
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# Install essential system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    git \
    unzip \
    nano \
    ca-certificates \
    gnupg \
    lsb-release \
    sudo \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI (for socket mounting, not Docker-in-Docker)
RUN curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js 22.x LTS
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install GitHub CLI
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && apt-get update \
    && apt-get install -y gh \
    && rm -rf /var/lib/apt/lists/*

# Install ttyd for web terminal (pinned to version 1.7.7)
RUN wget -q https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.x86_64 -O /usr/local/bin/ttyd \
    && chmod +x /usr/local/bin/ttyd

# Install Claude Code via npm
RUN npm install -g @anthropic-ai/claude-code

# =============================================================================
# GENERIC INSTALLATION END
# =============================================================================

# =============================================================================
# CUSTOM INSTALLATION START - Add your custom tools here
# =============================================================================

# Example custom tools (fork this repo and add your tools here):
# RUN apt-get update && apt-get install -y your-custom-package
# RUN npm install -g your-favorite-cli-tool
# RUN curl -L your-script-url | bash

# =============================================================================
# CUSTOM INSTALLATION END
# =============================================================================

# Create development user (runtime user mapping will override this)
RUN useradd -m -s /bin/bash devuser \
    && echo "devuser ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

# Create workspace directory for development
RUN mkdir -p /workspace \
    && chown devuser:devuser /workspace

# Set working directory
WORKDIR /workspace

# Switch to development user
USER devuser

# Copy and set up entrypoint
COPY docker-ttyd/entrypoint.sh /entrypoint.sh
USER root
RUN chmod +x /entrypoint.sh
USER devuser

# Expose ttyd port
EXPOSE 7681

# Use entrypoint for runtime configuration
ENTRYPOINT ["/entrypoint.sh"]