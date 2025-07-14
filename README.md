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
  -v ./.env:/config/.env:ro \
  -p 80:80 \
  -e GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx \
  -e OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxx \
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
OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxx
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
    ports:
      - "80:80"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - pocketdev-workspace:/workspace
      - pocketdev-home:/home/pocketdev
      - ./.env:/config/.env:ro
    environment:
      - GITHUB_TOKEN=${GITHUB_TOKEN}
      - OPENAI_API_KEY=${OPENAI_API_KEY}
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
- `OPENAI_API_KEY` (optional) - Your OpenAI API key for voice transcription features
- `TZ` (optional) - Your timezone (defaults to UTC)

### What's Included

- Ubuntu 24.04 LTS base
- **Mobile Voice Terminal**:
  - TTYD web-based terminal with mobile optimization
  - OpenAI Whisper API integration for voice-to-text
  - Mobile-responsive interface with touch-friendly controls
  - Keyboard fallback for traditional input
- **Web Interface**:
  - Nginx web server for terminal access
  - Responsive design for mobile and desktop
  - Real-time voice transcription and command injection
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
# Start PocketDev with web interface and voice features
docker run -it -d \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v pocketdev-workspace:/workspace \
  -v pocketdev-home:/home/pocketdev \
  -v ./.env:/config/.env:ro \
  -p 80:80 \
  -e GITHUB_TOKEN=your_github_token_here \
  -e OPENAI_API_KEY=your_openai_api_key_here \
  --name pocketdev \
  tetrixdev/pocket-dev

# Access the mobile terminal interface
# Web Terminal: http://localhost
# Voice features: Tap microphone button to dictate commands

# Attach to the container for direct access
docker attach pocketdev

# Inside the container, you can:
gh repo clone your-username/your-repo
claude  # Start Claude Code AI assistant
docker run -d -p 5432:5432 postgres:latest
```

### Mobile Voice Features

- **Voice Commands**: Tap the microphone button to record voice commands
- **Real-time Transcription**: Speech is converted to text using OpenAI Whisper API
- **Mobile Optimized**: Responsive design works on phones and tablets
- **Cost Efficient**: ~$0.006 per minute of voice input (~$1.50/month for regular use)

### Android HTTPS Requirement for Voice Input

Modern browsers require HTTPS for microphone access. For local development on Android:

**Option 1: Use `chrome://flags/` (Recommended)**
1. Open Chrome on Android
2. Go to `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
3. Add your local IP address (e.g., `http://192.168.1.100`)
4. Restart Chrome

**Option 2: Use Android Chrome Dev Tools**
1. Enable Developer Options on Android
2. Enable USB Debugging
3. Connect to computer via USB
4. Use Chrome DevTools port forwarding

**Option 3: Use Self-Signed Certificate**
```bash
# Generate self-signed cert and run with HTTPS
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -days 365 -nodes
# Then configure nginx with SSL
```

## Building from Source

```bash
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev
docker build -t pocket-dev .
```