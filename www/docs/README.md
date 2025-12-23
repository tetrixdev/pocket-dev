# PocketDev Documentation

## TLDR - Quick Navigation

| What you need | Go to |
|---------------|-------|
| Philosophy & design principles | [architecture/philosophy.md](architecture/philosophy.md) |
| Provider system (Claude Code, Anthropic, etc.) | [architecture/providers.md](architecture/providers.md) |
| Tool system (how tools work) | [tool-system-architecture.md](tool-system-architecture.md) |
| Memory system (persistent storage) | [memory-system.md](memory-system.md) |
| Testing plans | [tool-system-testing-plan.md](tool-system-testing-plan.md) |

---

## What is PocketDev?

PocketDev is a **Docker-based Laravel development environment** that wraps multiple AI providers (Claude Code CLI, Anthropic API, OpenAI API, Codex CLI) into a unified interface with:

1. **Persistent Memory** - Vector-based semantic search for knowledge that survives across conversations
2. **Extensible Tools** - User-creatable bash script tools that AI can invoke
3. **Multi-Provider Support** - Use different AI backends while maintaining consistent capabilities
4. **Self-Improvement** - AI can modify PocketDev's own code (dogfooding)

---

## Core Concepts

### Providers

Providers are the AI backends that power conversations:

| Provider | Type | Native Tools | PocketDev Tools |
|----------|------|--------------|-----------------|
| Claude Code | CLI | Yes (Bash, Read, Write, etc.) | Memory, Tool Management, User Tools |
| Codex | CLI | Yes (similar to Claude Code) | Memory, Tool Management, User Tools |
| Anthropic | API | No | All tools (including file ops) |
| OpenAI | API | No | All tools (including file ops) |

See [architecture/providers.md](architecture/providers.md) for details.

### Tools

Tools extend AI capabilities. PocketDev has three types:

1. **Native Tools** - Built into CLI providers (Claude Code, Codex)
2. **PocketDev Tools** - PHP classes that implement `Tool` interface
3. **User Tools** - Bash scripts created by users or AI

See [tool-system-architecture.md](tool-system-architecture.md) for the complete tool system.

### Memory System

A PostgreSQL + pgvector based semantic storage system:

- **Structures** - User-defined schemas (like templates)
- **Objects** - Instances of structures (with JSONB data)
- **Embeddings** - Vector representations for semantic search

See [memory-system.md](memory-system.md) for details.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Web UI (Blade + Alpine.js)               │
├─────────────────────────────────────────────────────────────────┤
│                      Laravel Controllers                         │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │   Agents    │  │Conversations│  │    System Prompt        │  │
│  │  (config)   │  │  (history)  │  │     Builder             │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
├─────────────────────────────────────────────────────────────────┤
│                      Provider Factory                            │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐            │
│  │ Anthropic│ │  OpenAI  │ │ClaudeCode│ │  Codex   │            │
│  │ Provider │ │ Provider │ │ Provider │ │ Provider │            │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘            │
├─────────────────────────────────────────────────────────────────┤
│                      Tool System                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐           │
│  │ ToolRegistry │  │ ToolSelector │  │  UserTool    │           │
│  │ (auto-disc.) │  │  (filtering) │  │  (wrapper)   │           │
│  └──────────────┘  └──────────────┘  └──────────────┘           │
│  ┌──────────────────────────────────────────────────┐           │
│  │              PHP Tool Classes                     │           │
│  │  MemoryQuery, MemoryCreate, ToolCreate, etc.     │           │
│  └──────────────────────────────────────────────────┘           │
├─────────────────────────────────────────────────────────────────┤
│                     Memory System                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐           │
│  │  Structures  │  │   Objects    │  │  Embeddings  │           │
│  │  (schemas)   │  │ (instances)  │  │  (vectors)   │           │
│  └──────────────┘  └──────────────┘  └──────────────┘           │
├─────────────────────────────────────────────────────────────────┤
│        PostgreSQL + pgvector      │      Redis (queues)         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Key Files

### Models
- `app/Models/Agent.php` - AI agent configuration
- `app/Models/Conversation.php` - Chat history
- `app/Models/PocketTool.php` - User-created tools (database)
- `app/Models/MemoryStructure.php` - Memory schemas
- `app/Models/MemoryObject.php` - Memory instances

### Services
- `app/Services/ToolRegistry.php` - Auto-discovers and registers tools
- `app/Services/ToolSelector.php` - Filters tools for providers
- `app/Services/ProviderFactory.php` - Creates AI providers
- `app/Services/SystemPromptBuilder.php` - Builds system prompts

### Tools (PHP Classes)
- `app/Tools/Tool.php` - Base class for all tools
- `app/Tools/UserTool.php` - Wrapper for database tools
- `app/Tools/Memory*.php` - Memory system tools
- `app/Tools/Tool*.php` - Tool management tools
- `app/Tools/*Tool.php` - File operation tools

### Enums
- `app/Enums/Provider.php` - Single source of truth for provider identifiers

---

## Design Documents

- [Philosophy & Principles](architecture/philosophy.md) - Why we made these choices
- [Provider System](architecture/providers.md) - How providers work
- [Tool System](tool-system-architecture.md) - Complete tool architecture
- [Memory System](memory-system.md) - Semantic storage system
