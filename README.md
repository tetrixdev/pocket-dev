# PocketDev

Web-accessible, mobile-friendly Docker-based development environment with Docker-out-of-Docker (DooD) support.

## Quick Start

### Prerequisites

- Docker installed on your system
- GitHub Personal Access Token with required permissions
- Anthropic account or API key for Claude Code

### GitHub Token Setup

1. Go to GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Generate a new token with these **required** permissions:
   - `repo` - Full repository access
   - `workflow` - Update GitHub Action workflows
   - `user:email` - Access user email addresses

3. **Optional permissions** (add based on your development needs):
   - `write:packages` & `read:packages` - Push/pull Docker images to GitHub Container Registry (ghcr.io)
   - `gist` - Create and manage gists for code snippets
   - `notifications` - Manage GitHub notifications
   - `admin:repo_hook` - Manage repository webhooks
   - `codespace` - Manage GitHub Codespaces (if using)

### Running PocketDev

#### Option 1: Docker Run

```bash
docker run -it -d \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v pocketdev-workspace:/workspace \
  -v pocketdev-home:/home/pocketdev \
  -e GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx \
  -e TZ=Europe/Amsterdam \
  --name pocketdev \
  tetrixdev/pocket-dev
```

Then attach to the container:
```bash
docker attach pocketdev
```

To detach without stopping: Press `Ctrl+P`, then `Ctrl+Q`

#### Option 2: Docker Compose

Create a `.env` file:
```env
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx
TZ=Europe/Amsterdam
```

Create a `docker-compose.yml` file:
```yaml
services:
  pocketdev:
    image: tetrixdev/pocket-dev
    container_name: pocketdev
    stdin_open: true
    tty: true
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - pocketdev-workspace:/workspace
      - pocketdev-home:/home/pocketdev
    environment:
      - GITHUB_TOKEN=${GITHUB_TOKEN}
      - TZ=${TZ:-UTC}
    restart: unless-stopped
volumes:
  pocketdev-workspace:
  pocketdev-home:
```

Run with:
```bash
docker-compose up -d
docker attach pocketdev
```

### Environment Variables

- `GITHUB_TOKEN` (required) - Your GitHub Personal Access Token
- `TZ` (optional) - Your timezone (defaults to UTC)

### What's Included

- Ubuntu 24.04 LTS base
- Node.js 22.x LTS
- Git version control
- GitHub CLI (gh) - pre-authenticated with your token
- Claude Code CLI - AI-powered development assistant
- Docker CLI - for running containers from within PocketDev
- Essential tools: curl, wget, unzip, nano

### Docker-out-of-Docker (DooD)

PocketDev uses DooD approach, meaning you can run any Docker containers from within the development environment. This allows you to:

- Run databases (PostgreSQL, Redis, etc.)
- Spin up web servers (Nginx, Apache, etc.)
- Test your applications in containers
- Use official Docker images

### Customization

You can extend PocketDev by:

1. **Forking this repository** and modifying the Dockerfile
2. **Using Docker inheritance**:
   ```dockerfile
   FROM tetrixdev/pocket-dev
   RUN apt-get update && apt-get install -y your-tools
   ```

### Example Usage

```bash
# Start PocketDev
docker run -it -d \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v pocketdev-workspace:/workspace \
  -v pocketdev-home:/home/pocketdev \
  -e GITHUB_TOKEN=your_github_token_here \
  --name pocketdev \
  tetrixdev/pocket-dev

# Attach to the container
docker attach pocketdev

# Inside the container, you can:
gh repo clone your-username/your-repo
claude  # Start Claude Code AI assistant
docker run -d -p 5432:5432 postgres:latest
docker run -d -p 80:80 nginx:latest
```

## Building from Source

```bash
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev
docker build -t pocket-dev .
```