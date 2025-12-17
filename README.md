# PocketDev

AI-powered development environment with Claude Code integration. Run Claude Code in a containerized environment with full terminal access.

## Quick Start

**Prerequisites**: Docker and Docker Compose

```bash
mkdir pocket-dev && cd pocket-dev && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/setup.sh -o setup.sh && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/compose.yml -o compose.yml && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/.env.example -o .env.example && \
chmod +x setup.sh && ./setup.sh
```

Then open http://localhost (or your configured port) and follow the setup wizard.

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

## Contributing

Want to contribute to PocketDev? See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup.

## License

PocketDev is source-available software. Free for personal and commercial development work. See [LICENSE.md](LICENSE.md) for details.
