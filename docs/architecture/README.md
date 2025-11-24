# Architecture Overview

PocketDev uses a **multi-container Docker architecture** designed for separation of concerns and ease of maintenance. The system provides a web-based Claude Code interface optimized for mobile devices.

## System Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              User Browser                                │
│                    (Desktop or Mobile Device)                            │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │ HTTP/HTTPS
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         pocket-dev-proxy                                 │
│                    (Nginx Reverse Proxy)                                 │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ • Basic Authentication (htpasswd)                                │   │
│  │ • Optional IP Whitelist                                          │   │
│  │ • SSL Termination (production)                                   │   │
│  │ • Request Routing                                                │   │
│  │ • WebSocket/SSE Support (proxy_buffering off)                    │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└───────────────┬─────────────────────────────────┬───────────────────────┘
                │                                 │
    ┌───────────▼───────────┐       ┌─────────────▼─────────────┐
    │   pocket-dev-nginx    │       │     pocket-dev-ttyd       │
    │   (Laravel Server)    │       │    (Web Terminal)         │
    │                       │       │    [DEPRECATED]           │
    │  • Static files       │       │                           │
    │  • FastCGI to PHP     │       │  • ttyd server            │
    │  • SSE streaming      │       │  • tmux sessions          │
    └───────────┬───────────┘       │  • Claude CLI direct      │
                │                   └───────────────────────────┘
                │ FastCGI :9000
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                          pocket-dev-php                                  │
│                    (Laravel Application)                                 │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ • PHP 8.4-FPM                                                    │   │
│  │ • Laravel 11                                                     │   │
│  │ • Claude Code CLI (npm global)                                   │   │
│  │ • Node.js 22 (asset building)                                    │   │
│  │ • Docker CLI (container management)                              │   │
│  │ • GitHub CLI                                                     │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        pocket-dev-postgres                               │
│                      (PostgreSQL 17 Database)                            │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ • Session metadata                                               │   │
│  │ • Model pricing                                                  │   │
│  │ • Encrypted app settings                                         │   │
│  │ • (Messages NOT stored here - in .jsonl files)                   │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
```

## Design Principles

### 1. Separation of Concerns (Docker Best Practice)

Each container has a single responsibility:
- **Proxy**: Security and routing
- **Nginx**: HTTP server
- **PHP**: Application logic
- **PostgreSQL**: Data persistence
- **TTYD**: Terminal access (deprecated)

**Why?** Easier to update, scale, and debug individual components without affecting others.

### 2. Mobile-First Interface

The chat interface implements a **dual-container pattern**:
- Both desktop and mobile layouts exist in DOM simultaneously
- CSS media queries control visibility
- JavaScript updates BOTH containers on every message
- Different scroll behaviors: container scroll (desktop) vs page scroll (mobile)

**Why?** Seamless experience across devices without JavaScript layout switching.

### 3. Single Source of Truth for Messages

Messages stored in Claude's native `.jsonl` files, not database:
- Claude CLI reads/writes these files directly
- Web UI reads from same files
- Database stores only metadata (session info, not content)

**Why?** Compatibility with Claude CLI, simpler data management, no schema migrations for message format changes.

### 4. Streaming-First Communication

Real-time responses via Server-Sent Events (SSE):
- Backend spawns Claude CLI process
- Output written to temp file
- PHP tails file and streams to browser
- Reconnection replays existing lines

**Why?** Immediate feedback, supports long-running operations, connection-resilient.

## Networks

| Network | Purpose | Containers |
|---------|---------|------------|
| `pocket-dev` | Internal communication | All 5 containers |
| `pocket-dev-public` | External access point | proxy only |

## Volumes

| Volume | Purpose | Mount Points |
|--------|---------|--------------|
| `pocket-dev-workspace` | User projects | `/workspace` (PHP, TTYD) |
| `pocket-dev-user` | User home directories | `/home/appuser`, `/home/devuser` |
| `pocket-dev-postgres` | Database files | `/var/lib/postgresql/data` |
| `pocket-dev-proxy-config` | Editable nginx config | `/etc/nginx-proxy-config` |

## Related Documentation

- [Docker Containers](docker-containers.md) - Detailed container configurations
- [Chat Interface](chat-interface.md) - Frontend architecture and patterns
