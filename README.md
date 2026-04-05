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

**Don't have a server yet?** Run this from your local machine:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-cloud.sh | bash
```

This creates a Hetzner Cloud server and configures everything automatically.

**Already have a server?** SSH into it and run:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-server.sh | bash
```

Both scripts will:
- Install Docker, proxy-nginx, and Tailscale
- Configure SSL certificates
- Set up PocketDev with your domain

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

Use the one-liners in Quick Start above. The setup provides:

- **Hetzner Cloud** - One-click server creation with backup option
- **Tailscale** - SSH access only via your private network
- **proxy-nginx** - Reverse proxy with automatic SSL certificates
- **Docker hardening** - Containers isolated from public network
- **Automatic updates** - Security patches applied automatically

See [vps-setup](https://github.com/tetrixdev/vps-setup) for details on server hardening.

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
