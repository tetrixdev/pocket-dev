# PocketDev

AI-powered development environment with Claude Code integration. Run Claude Code in a containerized environment with full terminal access.

## Quick Start

### Local Development (Docker)

**Prerequisites**: Docker and Docker Compose

```bash
mkdir pocket-dev && cd pocket-dev && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/setup.sh -o setup.sh && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/compose.yml -o compose.yml && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/.env.example -o .env.example && \
chmod +x setup.sh && ./setup.sh
```

Then open http://localhost (or your configured port) and follow the setup wizard.

### Production Deployment (VPS)

For a secure production deployment on a VPS with SSL and Tailscale:

```bash
# 1. Run vps-setup (Docker, proxy-nginx, SSH hardening, Tailscale)
curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash

# 2. Install PocketDev (prompts for domain, configures SSL)
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash
```

See [vps-setup](https://github.com/tetrixdev/vps-setup) for details on server hardening.

## Features

- **Claude Code in Browser** - Full Claude Code CLI through a web interface
- **Multiple AI Providers** - Claude Code, Anthropic API, or OpenAI
- **Git Integration** - Configure GitHub credentials via web UI
- **Persistent Workspace** - Your projects survive container restarts
- **Self-Contained** - Everything runs in Docker, nothing installed on host

## First Run Setup

On first visit, PocketDev will guide you through:

1. **AI Provider Setup** - Configure Claude Code, Anthropic API, or OpenAI
2. **Git Credentials** (optional) - For git operations inside the environment

All configuration is done through the web UI.

## Updating

```bash
docker compose pull && docker compose up -d
```

## Deploy to a Server

Want to run PocketDev on a VPS? Use the [vps-setup](https://github.com/tetrixdev/vps-setup) + install.sh combo shown in Quick Start above. This provides:

- **Tailscale** - SSH access only via your private Tailscale network
- **proxy-nginx** - Reverse proxy with automatic SSL certificates
- **Docker hardening** - Containers isolated from public network
- **Automatic updates** - Security patches applied automatically

## Troubleshooting

### Claude Code Version Issues

If you encounter API errors like "tool use concurrency issues" or other Claude Code bugs, you can pin to a specific version by adding this to your `.env` file:

```bash
CLAUDE_CODE_VERSION=2.1.17
```

Then restart the containers:

```bash
docker compose down && docker compose up -d
```

The queue container will automatically install the specified version on startup. Remove the variable or set it empty to use the default (latest) version.

## Contributing

Want to contribute to PocketDev? See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup.

## License

PocketDev is source-available software. Free for personal and commercial development work. See [LICENSE.md](LICENSE.md) for details.
