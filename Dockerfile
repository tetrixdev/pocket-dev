FROM ubuntu:24.04

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# Install essential tools (including sudo)
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    git \
    unzip \
    nano \
    gnupg \
    lsb-release \
    sudo \
    && rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Create a non-root user and add to sudoers
RUN useradd -m -s /bin/bash pocketdev && \
    echo "pocketdev ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

# Install Docker CLI
RUN curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update && apt-get install -y docker-ce-cli && \
    rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Install Node.js 22.x LTS
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y nodejs && \
    rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Install GitHub CLI
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | gpg --dearmor -o /usr/share/keyrings/githubcli-archive-keyring.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null && \
    apt-get update && \
    apt-get install gh -y && \
    rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Install Claude Code CLI
RUN npm install -g @anthropic-ai/claude-code

# Set up workspace and config directories
RUN mkdir -p /workspace && chown pocketdev:pocketdev /workspace
RUN mkdir -p /home/pocketdev/.claude && chown pocketdev:pocketdev /home/pocketdev/.claude

# Copy scripts
COPY scripts/check-permissions.sh /usr/local/bin/check-permissions
COPY scripts/fix-docker-permissions.sh /usr/local/bin/fix-docker-permissions
COPY scripts/startup.sh /usr/local/bin/startup
RUN chmod +x /usr/local/bin/check-permissions /usr/local/bin/fix-docker-permissions /usr/local/bin/startup

# Switch to non-root user
USER pocketdev
WORKDIR /workspace

# Run startup script
CMD ["startup"]