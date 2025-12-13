# Technology Stack

## Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.4 | Application runtime |
| Laravel | 11.x | Web framework |
| PostgreSQL | 17 | Database |
| Nginx | Alpine | Web server / reverse proxy |

**Why Laravel?**
- Rapid development with Blade templates
- Built-in session management, encryption, validation
- Familiar to PHP developers

**Why PostgreSQL?**
- Better JSON support than MySQL
- JSONB for future schema flexibility
- Alpine image is lightweight

## Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| Alpine.js | 3.x | Reactive state management |
| Tailwind CSS | 3.x | Styling |
| marked.js | - | Markdown rendering |
| highlight.js | - | Code syntax highlighting |
| Vite | 5.x | Asset bundling |

**Why Alpine.js?**
- Lightweight (no build step for simple reactivity)
- Works well with Blade templates
- Easy to understand for non-SPA developers

**Why not Vue/React?**
- Adds complexity for a relatively simple UI
- Alpine.js sufficient for current needs
- Can be migrated later if needed

## Infrastructure

| Technology | Version | Purpose |
|------------|---------|---------|
| Docker | - | Containerization |
| Docker Compose | - | Multi-container orchestration |
| Redis | 7 | Caching and queues |

**Why Docker?**
- Consistent environment across machines
- Easy deployment
- Isolation between services

**Why Redis?**
- Fast in-memory cache
- Queue backend for Laravel jobs
- Session and broadcast support

## External Services

| Service | Purpose |
|---------|---------|
| Claude Code CLI | AI assistant (core functionality) |
| OpenAI Whisper | Voice transcription (optional) |

**Why Claude CLI wrapper instead of direct API?**
- CLI handles session management, tool use, streaming
- Consistent with how users would use Claude locally
- No need to re-implement Claude's conversation logic

## Key Dependencies

### PHP (composer.json)

```
laravel/framework: ^11.0
```

Standard Laravel installation with minimal additional packages.

### JavaScript (package.json)

```
alpinejs: ^3.x
tailwindcss: ^3.x
vite: ^5.x
```

### System-Level (Dockerfiles)

**PHP Container:**
- PHP 8.4-FPM with extensions
- Node.js 22 (for Vite)
- Composer
- Claude Code CLI (`@anthropic-ai/claude-code`)
- GitHub CLI
- Docker CLI

## Build Process

### Frontend Assets

```bash
# Runs automatically on container start
npm install
npm run build
```

**Why not `npm run dev`?**
- Vite dev server only listens on one domain
- App accessed via `localhost` (desktop) AND `192.168.x.x` (mobile)
- Build mode works for both access methods

**Source:** `docker-laravel/local/php/entrypoint.sh:73-74`

### Container Build

```bash
docker compose up -d --build
```

Builds:
- `pocket-dev-proxy` from `docker-proxy/shared/Dockerfile`
- `pocket-dev-php` from `docker-laravel/local/php/Dockerfile`

Others use stock images (nginx:alpine, postgres:17-alpine, redis:7-alpine).

## Future Considerations

**Potential Improvements:**
1. Extract frontend JS to separate files (chat.blade.php is 1500+ lines)
2. Add TypeScript for better type safety
3. Consider Livewire for more Laravel-native reactivity

**Migration Path:**
- Current architecture allows incremental improvements
- Alpine.js → Vue/Livewire migration possible without full rewrite
- Database schema is simple, easy to extend
