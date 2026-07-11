# Database

PostgreSQL 17 database for PocketDev metadata and settings.

## Design Philosophy

**Messages are NOT stored in database.** Claude CLI manages its own session files (`.jsonl`). The database stores only:
- Session metadata (title, project, status)
- Application settings (encrypted)
- Model pricing for cost calculation

This avoids sync complexity between database and Claude's files.

## Contents

- `schema.md` - Current database schema (NOT migration history)

## Connection

```
Host: pocket-dev-postgres
Port: 5432
Database: pocket-dev
User: pocket-dev
Password: (from DB_PASSWORD env var)
```

## Quick Reference

| Table | Purpose |
|-------|---------|
| `claude_sessions` | Session metadata (NOT messages) |
| `app_settings` | Encrypted key-value settings |
| `model_pricing` | Token pricing for cost calculation |
| `users` | Standard Laravel users (unused) |
| `cache` | Laravel cache storage |
| `jobs` | Laravel queue jobs |
