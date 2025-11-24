# Modules Overview

PocketDev has a single primary module: **Claude Integration**. This module handles all Claude Code AI functionality including CLI execution, streaming, session management, cost tracking, and voice transcription.

## Module Index

| Module | Description | Key Files |
|--------|-------------|-----------|
| [Claude Integration](claude-integration.md) | Claude Code AI integration | ClaudeCodeService, ClaudeController, chat.blade.php |

## Module Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                       Claude Integration Module                      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────┐    ┌─────────────────────────────────┐   │
│  │   ClaudeCodeService │───▶│     Claude Code CLI             │   │
│  │   (CLI Wrapper)     │    │     (proc_open execution)       │   │
│  └──────────┬──────────┘    └─────────────────────────────────┘   │
│             │                                                       │
│             ▼                                                       │
│  ┌─────────────────────┐    ┌─────────────────────────────────┐   │
│  │   ClaudeController  │───▶│     Session .jsonl Files        │   │
│  │   (API Endpoints)   │    │     (Message Storage)           │   │
│  └──────────┬──────────┘    └─────────────────────────────────┘   │
│             │                                                       │
│             ▼                                                       │
│  ┌─────────────────────┐    ┌─────────────────────────────────┐   │
│  │   chat.blade.php    │───▶│     Browser UI                  │   │
│  │   (Frontend)        │    │     (SSE, Alpine.js)            │   │
│  └─────────────────────┘    └─────────────────────────────────┘   │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  Supporting Services:                                               │
│  • OpenAIService - Voice transcription (Whisper)                   │
│  • AppSettingsService - Encrypted settings storage                  │
│  • ClaudeAuthController - Credential management                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Data Flow

### Query Flow

```
User Input → ClaudeController → ClaudeCodeService → Claude CLI
                                                         │
                                                         ▼
Frontend ← SSE Events ← PHP File Tail ← stdout.jsonl file
```

### Session Flow

```
Create Session → ClaudeSession (DB)
                      │
                      ├─ Stores: title, project_path, turn_count, status
                      │
                      └─ Links to: .jsonl file via claude_session_id
```

### Cost Flow

```
Claude Response → usage data in .jsonl → ModelPricing lookup → cost calculation
                                                                     │
                                                                     ▼
                                              Frontend display (per-message + total)
```
