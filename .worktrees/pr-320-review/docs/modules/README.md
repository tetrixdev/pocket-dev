# Modules

PocketDev has two main modules:

| Module | Complexity | Description |
|--------|------------|-------------|
| Chat | High | Web interface for Claude conversations |
| Config Editor | Medium | CRUD for Claude configuration files |

## Chat (`chat/`)

The primary user-facing module. Provides a web interface for interacting with Claude Code CLI.

**Key features:**
- Real-time streaming via SSE
- Session management (create, load, continue)
- Voice input via OpenAI Whisper
- Cost tracking per message and session
- Mobile-responsive design with Tailwind CSS

**Complexity:** High - ~1500 line Blade file with extensive JavaScript.

**Read when:** Working on chat interface, streaming, or session handling.

## Config Editor (`config-editor.md`)

Administrative module for editing Claude configuration files.

**Key features:**
- Edit CLAUDE.md, settings.json, nginx.conf
- CRUD for agents, commands, skills
- JSON editing for hooks

**Complexity:** Medium - Standard Laravel CRUD operations.

**Read when:** Working on configuration management.
