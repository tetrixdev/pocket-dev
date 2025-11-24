# Design Decisions

This document captures the key architectural and design decisions for PocketDev, including the rationale behind each choice.

## Vision & Target Audience

### Decision: Personal Development Tool

**Choice**: Single-developer, local use, maximum simplicity

**Rationale**:
- Primary use case: Coding from mobile devices
- No need for multi-tenant complexity
- Authentication handled at proxy level (Basic Auth)
- No user accounts needed in application

**Implications**:
- Simple permission model
- No role-based access control
- Single credential file per container
- Focus on developer experience, not enterprise features

---

## Architecture Decisions

### Decision: Multi-Container Docker Architecture

**Choice**: 5 separate containers (proxy, PHP, nginx, PostgreSQL, TTYD)

**Rationale**:
- Docker best practice: one process per container
- Separation of concerns for easier maintenance
- Can update individual components without affecting others
- Clear security boundaries (proxy handles auth)

**Trade-offs**:
- More complex than monolith
- Requires understanding of container networking
- Credentials are container-specific

**Future**: TTYD container will be deprecated; web chat UI replaces terminal.

---

### Decision: Messages in .jsonl Files (Not Database)

**Choice**: Store messages in Claude CLI's native format

**Current State**: Piggyback on Claude CLI's file storage

**Rationale**:
- Single source of truth with CLI
- No schema migrations for message format changes
- CLI reads/writes same files
- Simpler data management

**Future Direction**: Migrate to database storage

**Planned Approach**:
1. Store messages in native JSON format in database
2. Transform to display format when rendering
3. Enables better UI control over message rendering
4. Keep CLI compatibility via export/sync

**Why Change?**:
- Better query capabilities
- Custom UI rendering without CLI limitations
- Easier backup/restore
- Support for message editing/deletion

---

### Decision: Server-Sent Events for Streaming

**Choice**: SSE instead of WebSockets

**Rationale**:
- Simpler implementation (HTTP-based)
- Works through proxies without special config
- Reconnection built into browser API
- Sufficient for one-way streaming (responses)

**Implementation**:
- Backend writes to temp file
- PHP tails file and sends SSE events
- Supports reconnection (replays existing lines)

**Trade-offs**:
- One-way only (server → client)
- Can't push interrupts from server
- File-based streaming adds complexity

---

### Decision: Dual-Container Responsive Pattern

**Choice**: Two DOM trees for desktop/mobile, CSS-controlled

**Rationale**:
- No JavaScript layout switching
- Instant transitions on resize
- Shared Alpine.js state
- Different scroll behaviors per platform

**Trade-offs**:
- Must update BOTH containers on every DOM change
- Duplicate markup in template
- Larger HTML payload

**Why Not Single Responsive Container?**:
- Desktop uses container scroll, mobile uses page scroll
- Different structural layouts (sidebar vs drawer)
- Keeping both in DOM is simpler than JS switching

---

## UI/UX Decisions

### Decision: Mobile-First Development

**Choice**: Optimize for phone usage

**Rationale**:
- Primary use case: Coding away from desktop
- Voice transcription enables hands-free input
- GitHub-centric workflow (files in cloud)
- Simple, functional interface over feature-rich

**Key Features**:
- Voice recording with Whisper transcription
- Responsive design (works on any screen size)
- Touch-friendly controls
- Minimal, focused interface

---

### Decision: Voice Transcription via OpenAI Whisper

**Choice**: External API (OpenAI) instead of browser-based

**Rationale**:
- Higher accuracy than browser Speech API
- Works consistently across browsers/devices
- gpt-4o-transcribe handles technical terminology
- Async processing (audio uploaded, transcribed, returned)

**Trade-offs**:
- Requires OpenAI API key
- Network latency for transcription
- Cost per transcription
- Microphone requires secure context (localhost/HTTPS)

---

### Decision: GitHub-Centric Approach

**Choice**: Acceptable to narrow scope to GitHub workflows

**Rationale**:
- Most common VCS for target audience
- Pre-authenticated via GIT_TOKEN
- Can leverage gh CLI for operations
- Reduces complexity vs supporting multiple VCS

**Implications**:
- Git integration is first-class
- GitHub-specific features (PRs, issues) accessible
- Other Git hosts work but not optimized

---

## Configuration Decisions

### Decision: Config UI Priority Varies by Frequency

**Choice**: Full UI for frequent changes, file editing for rare changes

**Priority Levels**:

| Feature | Priority | Reason |
|---------|----------|--------|
| Skills/Agents | High | Changed often, complex to edit manually |
| Slash Commands | High | Frequent additions |
| CLAUDE.md | Medium | Occasionally modified |
| settings.json | Low | Rarely changed |
| Nginx config | Low | Almost never changed |

**Rationale**:
- Phone editing of markdown is tedious
- Some settings rarely change after initial setup
- Focus effort on frequently-used features

---

### Decision: Encrypted Settings in Database

**Choice**: AppSetting model with encrypted cast

**Rationale**:
- API keys shouldn't be in .env (visible in shell)
- Runtime configuration without file access
- Transparent encrypt/decrypt via Eloquent
- Survives container rebuilds (in database)

**Used For**:
- OpenAI API key
- User preferences (model, permission mode)

---

## Technical Decisions

### Decision: Basic Auth at Proxy Level

**Choice**: Nginx htpasswd, not Laravel auth

**Rationale**:
- Single point of enforcement
- Works for all routes (API, web, terminal)
- No session management needed
- Simple to configure

**Trade-offs**:
- Single user only (no roles)
- Credentials in .env file
- No granular permissions

---

### Decision: Thinking Mode via Environment Variable

**Choice**: `MAX_THINKING_TOKENS` env var

**Rationale**:
- Claude CLI reads this env var
- No CLI flag modification needed
- 5 levels: Off (0) through Ultrathink (32,000)

**Known Issue**: Slash commands create synthetic messages that break thinking mode. See `SLASH_COMMAND_ISSUE.md`.

---

### Decision: Cost Calculation Server-Side

**Choice**: Calculate in PHP, store in .jsonl metadata

**Rationale**:
- Consistent calculations
- Pricing from database, not hardcoded
- Frontend just displays cached values
- No recalculation on page load

---

## Related Documents

- [Configuration UI Analysis](configuration-ui.md) - File browser vs list+modal analysis
- [Config UI Conventions](config-ui-conventions.md) - Form styling and layout conventions
- [SLASH_COMMAND_ISSUE.md](../../SLASH_COMMAND_ISSUE.md) - Known thinking mode issue
