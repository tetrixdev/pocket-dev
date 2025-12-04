# Integrations

External service integrations used by PocketDev.

## Overview

| Integration | Purpose | Required |
|-------------|---------|----------|
| Claude CLI | AI assistant (core functionality) | Yes |
| OpenAI Whisper | Voice transcription | No (optional) |

## Claude CLI (`claude-cli.md`)

The core integration. PocketDev wraps Claude Code CLI to provide a web interface.

**Why CLI instead of direct API?**
- CLI handles session management, tool use, streaming
- Consistent behavior with local Claude Code usage
- No need to re-implement conversation logic

## OpenAI Whisper (`openai-whisper.md`)

Optional voice transcription for voice-to-text input.

**Requirements:**
- OpenAI API key (stored encrypted in database)
- Secure context for microphone access (HTTPS or localhost)
