# PocketDev - AI-Powered Development Environment

A complete, secure development environment with Laravel + PostgreSQL + AI-powered terminal access through Claude Code integration. Provides both web-based development through Claude Code and remote development support for VS Code and JetBrains IDEs.

## ✨ Features

- 🤖 **Claude Code Integration** - AI-powered development through web interface
- 🐘 **PHP 8.4-FPM** with Laravel, PostgreSQL, Composer, and Node.js 22 LTS
- 🌐 **Nginx Proxy** with security features (Basic Auth + IP Whitelist)
- 🖥️ **TTYD Terminal** - Web-based terminal with full development tools
- 🗄️ **PostgreSQL 17** with persistent data storage
- 🔥 **Vite Dev Server** with hot reload support
- 🔐 **Git & GitHub CLI** pre-configured with your credentials
- 📦 **Persistent Volumes** for workspace and user data
- 🚀 **Production Ready** with automated Docker image builds
- 🔧 **Remote Development** support for VS Code and JetBrains Gateway

## 🚀 Quick Setup

### Prerequisites

- Docker and Docker Compose installed
- GitHub Personal Access Token (for git operations)

### Development Setup

1. **Clone or create your Laravel project**:
   ```bash
   git clone https://github.com/your-username/pocket-dev.git
   cd pocket-dev
   ```

2. **Configure environment**:
   ```bash
   cp .env.example .env
   nano .env
   ```

   **Required settings**:
   ```bash
   # Git credentials (required for terminal git operations)
   GIT_TOKEN=ghp_your_github_token_here
   GIT_USER_NAME="Your Full Name"
   GIT_USER_EMAIL=your.email@domain.com

   # Security credentials (REQUIRED - set your own values)
   BASIC_AUTH_USER=your_username
   BASIC_AUTH_PASS=your_secure_password

   # Optional IP whitelist (comment out to allow all IPs)
   IP_WHITELIST=192.168.1.0/24,127.0.0.1
   ```

3. **Start the development environment**:
   ```bash
   docker compose up -d
   ```

4. **Access your environment**:
   - **Laravel App**: http://localhost (or your configured port)
   - **Terminal**: http://localhost/terminal-ws/
   - **Claude Code**: Use the web terminal interface for AI-powered development

## 🌍 Production Deployment

### 1. Configure Production Mode

Add these environment variables to your `.env`:

```bash
# Production deployment settings
DEPLOYMENT_MODE=production
DOMAIN_NAME=your-domain.com
```

**What this enables:**
- **IP Blocking**: Blocks direct IP access, only accepts requests to your domain
- **Security**: Prevents automated bot attacks scanning IP ranges

### 2. Set Up SSL/HTTPS (Required)

After deploying, configure SSL with certbot:

```bash
docker exec pocket-dev-proxy certbot --nginx -d your-domain.com
```

Certbot will automatically:
- Generate and install SSL certificates
- Configure HTTPS
- Create HTTP → HTTPS redirect
- Set up automatic certificate renewal

**Note:** All other settings (basic auth, git credentials, IP whitelist) work the same in both local and production.

## 🛠️ Architecture

### Container Services

- **pocket-dev-proxy**: Nginx reverse proxy with security features
- **pocket-dev-php**: Laravel application with PHP 8.4-FPM
- **pocket-dev-nginx**: Laravel web server
- **pocket-dev-postgres**: PostgreSQL 17 database
- **pocket-dev-ttyd**: Web terminal with development tools

### Security Features

1. **Basic Authentication**: Required HTTP Basic Auth protection
2. **IP Whitelist**: Optional restriction to specific IP ranges
3. **Secure Proxy**: Only proxy exposed - internal services protected
4. **Git Credentials**: Secure token-based git authentication

### Volume Strategy

- **pocket-dev-workspace**: Persistent project files and development workspace
- **pocket-dev-user**: User home directory (configs, SSH keys, etc.)
- **pocket-dev-postgres**: Database persistence
- **pocket-dev-proxy-config**: Proxy configuration data

## 📁 Project Structure

```
pocket-dev/
├── .github/workflows/          # CI/CD for Docker image builds
├── docker-laravel/            # Laravel container configuration
│   ├── local/                 # Development containers
│   ├── production/            # Production containers
│   └── shared/                # Shared configurations
├── docker-proxy/              # Nginx proxy with security
│   ├── local/                 # Development proxy
│   ├── production/            # Production proxy
│   └── shared/                # Shared proxy configs
├── docker-ttyd/               # Terminal container
├── www/                       # Laravel application
├── deploy/                    # Production deployment package
├── compose.yml                # Development Docker Compose
└── README.md                  # This file
```

## 🔧 Development Workflow

### Using Claude Code (Recommended)

1. Access the terminal at http://localhost/terminal-ws/
2. Claude Code provides AI-powered assistance for:
   - Code editing and refactoring
   - Debugging and troubleshooting
   - Architecture decisions
   - Documentation generation

### Using VS Code Remote Development

1. Install the "Remote - Containers" extension
2. Connect to the running container:
   ```bash
   # Get container ID
   docker ps | grep pocket-dev-ttyd

   # Connect with VS Code
   code --remote-host pocket-dev-ttyd:/workspace
   ```

### Using JetBrains Gateway

1. Install JetBrains Gateway
2. Configure SSH connection to the container
3. Open the workspace directory for development

### Common Development Commands

```bash
# Laravel commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan make:controller YourController

# Composer operations
docker compose exec pocket-dev-php composer install
docker compose exec pocket-dev-php composer require package/name

# NPM operations
docker compose exec pocket-dev-php npm install
docker compose exec pocket-dev-php npm run dev

# Database access
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev

# View logs
docker compose logs -f pocket-dev-php
docker compose logs -f pocket-dev-ttyd
```

## 🔐 Security Configuration

### Basic Authentication

Basic authentication is **required** for security. Set these in your .env file:

```bash
# In .env file (REQUIRED)
BASIC_AUTH_USER=your_username
BASIC_AUTH_PASS=your_secure_password
```

The proxy will fail to start if these credentials are not provided.

### IP Whitelist

Restrict access to specific IP ranges:

```bash
# In .env file
IP_WHITELIST=192.168.1.0/24,10.0.0.0/8,127.0.0.1
```

### Git Credentials

Configure git authentication for the terminal:

```bash
# In .env file
GIT_TOKEN=ghp_your_github_personal_access_token
GIT_USER_NAME="Your Full Name"
GIT_USER_EMAIL=your.email@domain.com
```

## 🚀 Production Deployment

### Automated Image Building

GitHub Actions automatically builds production Docker images when you create releases:

1. **Push your code to GitHub**
2. **Create a release** (e.g., `v1.0.0`)
3. **GitHub Actions builds**:
   - `ghcr.io/your-username/pocket-dev-php:v1.0.0`
   - `ghcr.io/your-username/pocket-dev-nginx:v1.0.0`
   - `ghcr.io/your-username/pocket-dev-ttyd:v1.0.0`
   - `ghcr.io/your-username/pocket-dev-proxy:v1.0.0`

### Deploying to Production

⚠️ **Important:** PocketDev is designed to run on standard web ports (80 for HTTP, 443 for HTTPS behind a reverse proxy). If you must use a non-standard port, you need to set `ASSET_URL` in your `.env` matching your full URL with port.

**Prerequisites:** Docker and Docker Compose installed on your server.

**Steps:**

1. **Download deployment files**:
   ```bash
   mkdir pocket-dev && cd pocket-dev
   wget https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/compose.yml
   wget https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/.env.example
   wget https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/README.md
   ```

2. **Configure environment**:
   ```bash
   cp .env.example .env
   # Edit .env with your preferred editor
   ```

   **Required: Update all values starting with `CHANGE_`** - Every variable with this prefix must be changed. Other variables use sensible defaults.

3. **Generate Laravel application key**:
   ```bash
   docker compose run --rm pocket-dev-php php artisan key:generate
   ```

4. **Deploy**:
   ```bash
   docker compose up -d
   ```

5. **Access**:
   - **Main App**: http://your-domain.com
   - **Terminal**: http://your-domain.com/terminal

## 🔄 Hard Reset (Development)

If you're developing pocket-dev and need to do a complete clean rebuild:

**Quick One-Liner:**
```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && docker compose down -v && docker compose up -d --build
```

**Step by Step:**
```bash
# 1. Stop and remove user containers using workspace volume
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm

# 2. Bring down pocket-dev with volume removal
docker compose down -v

# 3. Rebuild and start
docker compose up -d --build
```

**When to use:**
- After modifying files in `docker-ttyd/shared/defaults/`
- After changing Dockerfiles or entrypoints
- When you need fresh volumes for testing

See `CLAUDE.md` in the project root for detailed development instructions.

## 🐛 Troubleshooting

### Common Issues

**Permission denied in terminal**:
```bash
docker compose exec pocket-dev-ttyd sudo chown -R $(id -u):$(id -g) /workspace
```

**Git authentication not working**:
- Verify your GitHub token has repo permissions
- Check token format: `ghp_...` (not classic token)
- Restart the ttyd container: `docker compose restart pocket-dev-ttyd`

**Basic auth not working**:
- Ensure both `BASIC_AUTH_USER` and `BASIC_AUTH_PASS` are set
- Restart proxy: `docker compose restart pocket-dev-proxy`

**Can't access from external IP**:
- Check `IP_WHITELIST` configuration
- Remove or update IP ranges as needed

### Logs and Debugging

```bash
# View all logs
docker compose logs -f

# Individual service logs
docker compose logs -f pocket-dev-proxy
docker compose logs -f pocket-dev-ttyd
docker compose logs -f pocket-dev-php

# Check container health
docker compose ps

# Access container shell
docker compose exec pocket-dev-ttyd bash
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test with a fresh setup
5. Submit a pull request

## 📝 License

This project is open-sourced software licensed under the MIT license.

## 🆘 Support

- **Documentation**: Check this README and the `docker-laravel/production/README.md`
- **Issues**: Report bugs and feature requests on GitHub
- **Security**: For security issues, please email privately instead of opening issues

---

**Built for modern AI-powered development workflows with Claude Code integration** 🤖