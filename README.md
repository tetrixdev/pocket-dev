# PocketDev

AI-powered development environment with Claude Code integration. Run Claude Code in a containerized environment with full terminal access.

## Quick Start

### Production Deployment (Recommended)

For a secure production deployment, first run [vps-setup](https://github.com/tetrixdev/vps-setup) to configure your server:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash
```

Then install PocketDev:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash
```

The interactive wizard will guide you through:
1. Domain configuration with DNS verification
2. Access restriction (Tailscale, IP whitelist, or public)
3. SSL certificate setup

### Local Development

For local testing without a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash -s -- --local
```

Or with a custom port:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash -s -- --local --port=8080
```

### Installation Options

The installer supports both interactive mode and CLI parameters for automation:

```bash
# Interactive (recommended)
./install.sh

# Non-interactive examples
./install.sh --domain=pocketdev.example.com --restriction=tailscale
./install.sh --domain=pocketdev.example.com --restriction=whitelist --ips="1.2.3.4,10.0.0.0/8"
./install.sh --local --port=8080
```

| Option | Description |
|--------|-------------|
| `--domain=DOMAIN` | Domain for PocketDev (e.g., pocketdev.example.com) |
| `--restriction=MODE` | Access restriction: `tailscale`, `whitelist`, or `none` |
| `--ips=IPS` | IP whitelist (comma-separated, requires `--restriction=whitelist`) |
| `--local` | Local mode - skip domain/SSL setup |
| `--port=PORT` | Port for local mode (default: 80) |
| `--skip-dns-check` | Skip DNS verification |

### Prerequisites

- **Production**: Run [vps-setup](https://github.com/tetrixdev/vps-setup) first (installs Docker, proxy-nginx, configures firewall)
- **Local**: Docker and Docker Compose

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

## Security Architecture

PocketDev uses a two-layer security model when deployed with vps-setup:

1. **VPS-level (vps-setup)**: SSH access control via iptables
   - Tailscale-only mode: SSH only accessible via Tailscale
   - IP whitelist mode: SSH restricted to specific IPs
   - Open mode: Standard SSH access

2. **Web UI-level (install.sh)**: Application access via nginx
   - Tailscale restriction: Only 100.64.0.0/10 can access
   - IP whitelist: Custom IP restrictions
   - Public: No restriction (requires explicit confirmation)

See [vps-setup](https://github.com/tetrixdev/vps-setup) and [proxy-nginx](https://github.com/tetrixdev/proxy-nginx) for details.

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
