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
    net-tools \
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

# Add PHP 8.4 repository and install Nginx and PHP-FPM
RUN apt-get update && \
    apt-get install -y software-properties-common && \
    add-apt-repository ppa:ondrej/php -y && \
    apt-get update && \
    apt-get install -y nginx php8.4-fpm && \
    rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Install TTYD for web-based terminal
RUN apt-get update && \
    apt-get install -y wget && \
    wget -O /usr/local/bin/ttyd https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.x86_64 && \
    chmod +x /usr/local/bin/ttyd && \
    rm -rf /var/lib/apt/lists/*  # Clean apt cache to reduce image size

# Install Claude Code CLI
RUN npm install -g @anthropic-ai/claude-code

# Set up workspace and config directories
RUN mkdir -p /workspace && chown pocketdev:pocketdev /workspace
RUN mkdir -p /home/pocketdev/.claude && chown pocketdev:pocketdev /home/pocketdev/.claude
RUN mkdir -p /config && chmod 755 /config

# Expose port 80 for web access
EXPOSE 80

# Copy scripts
COPY scripts/check-permissions.sh /usr/local/bin/check-permissions
COPY scripts/fix-docker-permissions.sh /usr/local/bin/fix-docker-permissions
COPY scripts/setup-claude-config.sh /usr/local/bin/setup-claude-config
COPY scripts/startup.sh /usr/local/bin/startup
RUN chmod +x /usr/local/bin/check-permissions /usr/local/bin/fix-docker-permissions /usr/local/bin/setup-claude-config /usr/local/bin/startup

# Copy Claude configuration templates to safe location (not affected by volume mount)
COPY claude-config/CLAUDE.md /opt/pocketdev-templates/CLAUDE.md
COPY claude-config/settings.json /opt/pocketdev-templates/settings.json
RUN chown pocketdev:pocketdev /opt/pocketdev-templates/CLAUDE.md /opt/pocketdev-templates/settings.json

# Copy web files and configure Nginx
COPY web-config/nginx/default.conf /etc/nginx/sites-available/default
COPY web-config/html/ /var/www/html/
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Switch to non-root user
USER pocketdev
WORKDIR /workspace

# Run startup script
CMD ["startup"]