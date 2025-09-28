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

- **workspace-data**: Persistent project files and development workspace
- **user-data**: User home directory (configs, SSH keys, etc.)
- **postgres-data**: Database persistence

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

### Production Deployment

The `deploy/` folder contains everything needed for production:

1. **Copy deployment package to server**:
   ```bash
   scp -r deploy/ user@server:/path/to/deployment/
   cd /path/to/deployment/deploy/
   ```

2. **Configure production environment**:
   ```bash
   cp .env.example .env
   nano .env
   ```

   Update these critical settings:
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.com
   DB_PASSWORD=your_secure_database_password
   BASIC_AUTH_PASS=your_secure_admin_password
   ```

3. **Deploy with pre-built images**:
   ```bash
   # Deploy latest version
   docker compose up -d

   # Deploy specific version
   IMAGE_TAG=v1.0.0 docker compose up -d
   ```

## 🔧 Environment Variables

### Development (.env)

```bash
# Application
APP_NAME=pocket-dev
APP_ENV=local
APP_DEBUG=true

# Database
DB_CONNECTION=pgsql
DB_HOST=pocket-dev-postgres
DB_DATABASE=pocket-dev
DB_USERNAME=pocket-dev
DB_PASSWORD=auto-generated

# Ports
NGINX_PORT=80
VITE_PORT=5173
TTYD_PORT=7681

# Git credentials
GIT_TOKEN=ghp_your_token
GIT_USER_NAME="Your Name"
GIT_USER_EMAIL=your.email@domain.com

# Security (REQUIRED)
BASIC_AUTH_USER=your_username
BASIC_AUTH_PASS=your_secure_password

# Optional IP whitelist
IP_WHITELIST=192.168.1.0/24
```

### Production (deploy/.env)

```bash
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_PASSWORD=CHANGE_THIS_PASSWORD

# Security
BASIC_AUTH_USER=admin
BASIC_AUTH_PASS=CHANGE_THIS_PASSWORD
IP_WHITELIST=your.office.ip/32

# Image version
IMAGE_TAG=v1.0.0
```

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