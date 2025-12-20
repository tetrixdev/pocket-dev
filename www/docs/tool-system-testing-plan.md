# Tool System Testing Plan

This document outlines the testing plan for the PocketDev Tool System. It is divided into:
1. **Automated/CLI Tests** - Can be run by Claude Code via terminal
2. **Manual Integration Tests** - Require user interaction with the web UI

---

## Pre-Test Setup

Before running tests, ensure the database is migrated and seeded:

```bash
cd /var/www
php artisan migrate:fresh
php artisan db:seed --class=PocketToolSeeder
php artisan db:seed --class=ToolConflictSeeder
```

---

## Part 1: Automated/CLI Tests

These tests can be run entirely through the terminal.

### 1.1 Database Schema Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| DB-001 | Verify pocket_tools table exists | `php artisan tinker --execute="Schema::hasTable('pocket_tools')"` | `true` |
| DB-002 | Verify tool_conflicts table exists | `php artisan tinker --execute="Schema::hasTable('tool_conflicts')"` | `true` |
| DB-003 | Verify pocket_tools has all columns | `php artisan tinker --execute="Schema::getColumnListing('pocket_tools')"` | Should include: id, slug, name, description, source, category, capability, excluded_providers, native_equivalent, system_prompt, input_schema, script, enabled |

### 1.2 Seeder Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| SEED-001 | Verify PocketDev tools seeded | `php artisan tinker --execute="App\Models\PocketTool::where('source', 'pocketdev')->count()"` | `20` (6 file_ops + 8 memory + 6 tools) |
| SEED-002 | Verify memory tools exist | `php artisan tinker --execute="App\Models\PocketTool::where('category', 'memory')->pluck('slug')->toArray()"` | `['memory-structure-create', 'memory-structure-get', 'memory-structure-update', 'memory-structure-delete', 'memory-create', 'memory-query', 'memory-update', 'memory-delete']` |
| SEED-003 | Verify file_ops excluded from claude_code | `php artisan tinker --execute="App\Models\PocketTool::where('category', 'file_ops')->whereJsonContains('excluded_providers', 'claude_code')->count()"` | `6` |
| SEED-004 | Verify memory tools NOT excluded | `php artisan tinker --execute="App\Models\PocketTool::where('category', 'memory')->whereNull('excluded_providers')->count()"` | `8` |
| SEED-005 | Verify tool conflicts seeded | `php artisan tinker --execute="App\Models\ToolConflict::count()"` | `6` |

### 1.3 Tool List Command Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| LIST-001 | List all tools | `php artisan tool:list --json` | JSON with 20 tools |
| LIST-002 | List only memory tools | `php artisan tool:list --category=memory --json` | JSON with 8 memory tools |
| LIST-003 | List PocketDev tools only | `php artisan tool:list --pocketdev --json` | JSON with 20 tools |
| LIST-004 | List user tools (should be empty) | `php artisan tool:list --user --json` | JSON with 0 tools |
| LIST-005 | Filter by provider (claude_code) | `php artisan tool:list --provider=claude_code --json` | Should exclude file_ops tools (14 tools) |
| LIST-006 | Filter by provider (anthropic) | `php artisan tool:list --provider=anthropic --json` | Should include all 20 tools |

### 1.4 Tool Show Command Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| SHOW-001 | Show memory-create details | `php artisan tool:show memory-create` | JSON with tool details, source=pocketdev |
| SHOW-002 | Show pocketdev-bash details | `php artisan tool:show pocketdev-bash` | JSON with excluded_providers=["claude_code"] |
| SHOW-003 | Show non-existent tool | `php artisan tool:show non-existent` | Error: Tool not found |

### 1.5 Tool Create/Update/Delete Tests (User Tools)

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| CRUD-001 | Create user tool | `php artisan tool:create --slug=test-tool --name="Test Tool" --description="A test tool" --system-prompt="Use test-tool to test things" --script='#!/bin/bash\necho "{\"status\": \"ok\"}"'` | Success, returns tool ID |
| CRUD-002 | Verify user tool created | `php artisan tool:show test-tool` | JSON with source=user |
| CRUD-003 | Update user tool | `php artisan tool:update test-tool --name="Updated Test Tool"` | Success, shows changes |
| CRUD-004 | Run user tool | `php artisan tool:run test-tool` | JSON output: `{"status": "ok"}` |
| CRUD-005 | Delete user tool | `php artisan tool:delete test-tool` | Success message |
| CRUD-006 | Verify deletion | `php artisan tool:show test-tool` | Error: Tool not found |

### 1.6 PocketDev Tool Protection Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| PROT-001 | Cannot update PocketDev tool | `php artisan tool:update memory-create --name="Hacked"` | Error: Cannot modify PocketDev tool |
| PROT-002 | Cannot delete PocketDev tool | `php artisan tool:delete memory-create` | Error: Cannot delete PocketDev tool |
| PROT-003 | Cannot run PocketDev tool directly | `php artisan tool:run memory-create` | Error: Use php artisan memory:create instead |

### 1.7 Memory Structure Command Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| STRUCT-001 | Create structure | `php artisan memory:structure:create --name="Test Structure" --description="For testing" --schema='{"type":"object","properties":{"title":{"type":"string"},"notes":{"type":"string","x-embed":true}}}'` | Success, returns structure ID |
| STRUCT-002 | Get structure | `php artisan memory:structure:get test-structure` | JSON with structure details |
| STRUCT-003 | Update structure name | `php artisan memory:structure:update test-structure --name="Updated Name"` | Success, updated_fields includes name |
| STRUCT-004 | Update structure schema | `php artisan memory:structure:update test-structure --schema='{"type":"object","properties":{"title":{"type":"string"},"notes":{"type":"string","x-embed":true},"priority":{"type":"integer"}}}'` | Success, warnings about existing objects if any |
| STRUCT-005 | Delete structure | `php artisan memory:structure:delete test-structure` | Success (only if no objects exist) |
| STRUCT-006 | Delete non-empty structure fails | Create object first, then try delete | Error: Cannot delete, X objects exist |

### 1.8 Memory Object Command Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| MEM-001 | Query structures (empty) | `php artisan memory:query --sql="SELECT * FROM memory_structures"` | No results (or empty table) |
| MEM-002 | Create without structure fails | `php artisan memory:create --structure=nonexistent --name="Test"` | Error: Structure not found |
| MEM-003 | Query with invalid SQL fails | `php artisan memory:query --sql="DROP TABLE users"` | Error: Only SELECT allowed |

### 1.9 ToolSelector Service Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| SEL-001 | Get tools for claude_code | `php artisan tinker --execute="app(App\Services\ToolSelector::class)->getAvailableTools('claude_code')->pluck('slug')->toArray()"` | Should NOT include pocketdev-bash, pocketdev-read, etc. (14 tools) |
| SEL-002 | Get tools for anthropic | `php artisan tinker --execute="app(App\Services\ToolSelector::class)->getAvailableTools('anthropic')->pluck('slug')->toArray()"` | Should include ALL tools (20 tools) |
| SEL-003 | Get default tools for claude_code | `php artisan tinker --execute="app(App\Services\ToolSelector::class)->getDefaultTools('claude_code')->pluck('slug')->toArray()"` | Should exclude tools with native_equivalent |
| SEL-004 | Build system prompt for claude_code | `php artisan tinker --execute="app(App\Services\ToolSelector::class)->buildSystemPrompt('claude_code')"` | Should contain memory and tool instructions, NOT bash/read/edit |

### 1.10 ToolConflict Tests

| Test ID | Description | Command | Expected Result |
|---------|-------------|---------|-----------------|
| CONF-001 | Check conflict exists | `php artisan tinker --execute="App\Models\ToolConflict::findConflict('pocketdev-bash', 'native:Bash')?->conflict_type"` | `equivalent` |
| CONF-002 | Check no conflict | `php artisan tinker --execute="App\Models\ToolConflict::findConflict('memory-create', 'memory-query')"` | `null` |
| CONF-003 | Get conflicts for tool | `php artisan tinker --execute="App\Models\ToolConflict::getConflictsFor('pocketdev-bash')->count()"` | `1` |

---

## Part 2: Manual Integration Tests

These tests require interaction with the web UI or cannot be automated.

### 2.1 Claude Code Provider Integration

| Test ID | Description | Steps | Expected Result |
|---------|-------------|-------|-----------------|
| CC-001 | System prompt includes PocketDev tools | 1. Start a Claude Code conversation<br>2. Check the appended system prompt | Should include memory:create, memory:query, tool:create instructions |
| CC-002 | System prompt excludes file ops | Same as above | Should NOT include pocketdev-bash, pocketdev-read instructions |
| CC-003 | Memory tools work in Claude Code | 1. Start conversation<br>2. Ask: "List available memory structures using memory:query" | Claude Code should run: `php artisan memory:query --sql="SELECT * FROM memory_structures"` |
| CC-004 | Tool management works | 1. Ask: "Create a custom tool that shows the current date"<br>2. Ask: "Run the new tool" | Should successfully create and run the tool |

### 2.2 Anthropic Provider Integration

| Test ID | Description | Steps | Expected Result |
|---------|-------------|-------|-----------------|
| ANTH-001 | All tools available | 1. Start Anthropic conversation<br>2. Check available tools in request | Should include Bash, Read, Edit, Glob, Grep + memory + tool mgmt |
| ANTH-002 | Memory tools work | 1. Ask to query memory structures | Should use MemoryQueryTool |
| ANTH-003 | File ops work | 1. Ask to read a file | Should use ReadTool (PocketDev's) |

### 2.3 OpenAI Provider Integration

| Test ID | Description | Steps | Expected Result |
|---------|-------------|-------|-----------------|
| OAI-001 | All tools available | 1. Start OpenAI conversation<br>2. Check available tools | Should include all PocketDev tools |
| OAI-002 | Tools execute correctly | 1. Ask to list files in directory | Should use appropriate tool |

### 2.4 UI Tool Selector Integration

| Test ID | Description | Steps | Expected Result |
|---------|-------------|-------|-----------------|
| UI-001 | Tool selector shows categories | 1. Open quick settings<br>2. Check tool selection | Should show tools grouped by category |
| UI-002 | Provider filtering works | 1. Switch to Claude Code provider<br>2. Check available tools | Should not show file_ops tools for CC |
| UI-003 | Enable/disable persists | 1. Disable a tool in UI<br>2. Refresh page | Tool should remain disabled |

### 2.5 End-to-End Workflow Tests

| Test ID | Description | Steps | Expected Result |
|---------|-------------|-------|-----------------|
| E2E-001 | Full memory workflow | 1. Create memory structure<br>2. Create memory object<br>3. Query it<br>4. Update it<br>5. Delete it | All operations succeed |
| E2E-002 | Full custom tool workflow | 1. Create custom tool via AI<br>2. List tools to verify<br>3. Run the tool<br>4. Update the tool<br>5. Delete the tool | All operations succeed |
| E2E-003 | Provider switching | 1. Start with Claude Code<br>2. Use memory tools<br>3. Switch to Anthropic<br>4. Use same memory tools | Both should work correctly |

---

## Test Execution Checklist

### Phase 1: CLI Tests (Claude Code can execute)

```bash
# Run all CLI tests in sequence
# Copy and run these commands

# Setup
cd /var/www
php artisan migrate:fresh
php artisan db:seed --class=PocketToolSeeder
php artisan db:seed --class=ToolConflictSeeder

# DB Tests
php artisan tinker --execute="echo Schema::hasTable('pocket_tools') ? 'PASS' : 'FAIL'"
php artisan tinker --execute="echo Schema::hasTable('tool_conflicts') ? 'PASS' : 'FAIL'"

# Seeder Tests
php artisan tinker --execute="echo App\Models\PocketTool::where('source', 'pocketdev')->count() == 20 ? 'PASS' : 'FAIL'"
php artisan tinker --execute="echo App\Models\PocketTool::where('category', 'memory')->count() == 8 ? 'PASS' : 'FAIL'"
php artisan tinker --execute="echo App\Models\ToolConflict::count() == 6 ? 'PASS' : 'FAIL'"

# List Tests
php artisan tool:list --json | head -5
php artisan tool:list --category=memory --json
php artisan tool:list --provider=claude_code --json

# Show Tests
php artisan tool:show memory-create
php artisan tool:show pocketdev-bash

# CRUD Tests
php artisan tool:create --slug=test-tool --name="Test Tool" --description="Test" --system-prompt="Test prompt" --script='#!/bin/bash
echo "test"'
php artisan tool:show test-tool
php artisan tool:run test-tool
php artisan tool:delete test-tool

# Protection Tests
php artisan tool:update memory-create --name="Hacked" 2>&1 || echo "PASS: Update blocked"
php artisan tool:delete memory-create 2>&1 || echo "PASS: Delete blocked"

# ToolSelector Tests
php artisan tinker --execute="echo count(app(App\Services\ToolSelector::class)->getAvailableTools('claude_code')) == 14 ? 'PASS' : 'FAIL'"
php artisan tinker --execute="echo count(app(App\Services\ToolSelector::class)->getAvailableTools('anthropic')) == 20 ? 'PASS' : 'FAIL'"
```

### Phase 2: Manual Tests (User must execute)

1. **Start Docker environment**: `docker compose up -d`
2. **Access web UI**: Open browser to configured URL
3. **Test each provider**:
   - Claude Code: Create conversation, test memory commands
   - Anthropic: Create conversation, verify all tools available
   - OpenAI: Create conversation, verify all tools available
4. **Test UI components**: Quick settings, tool selector
5. **Run E2E workflows**: Full memory and custom tool workflows

---

## Test Results Template

```markdown
## Test Results - [DATE]

### CLI Tests
- [ ] DB-001:
- [ ] DB-002:
- [ ] SEED-001:
- [ ] SEED-002:
- [ ] SEED-003:
- [ ] SEED-004:
- [ ] SEED-005:
- [ ] LIST-001 through LIST-006:
- [ ] SHOW-001 through SHOW-003:
- [ ] CRUD-001 through CRUD-006:
- [ ] PROT-001 through PROT-003:
- [ ] MEM-001 through MEM-003:
- [ ] STRUCT-001 through STRUCT-006:
- [ ] SEL-001 through SEL-004:
- [ ] CONF-001 through CONF-003:

### Manual Tests
- [ ] CC-001 through CC-004:
- [ ] ANTH-001 through ANTH-003:
- [ ] OAI-001 through OAI-002:
- [ ] UI-001 through UI-003:
- [ ] E2E-001 through E2E-003:

### Issues Found
1.
2.
3.

### Notes
-
```

---

## Quick Reference After Compact

After `/compact`, reference this document to continue testing:

1. **Read this file**: `/var/www/docs/tool-system-testing-plan.md`
2. **Run Phase 1 CLI tests** using the checklist above
3. **Report results** and any failures
4. **User runs Phase 2 manual tests**
5. **Fix any issues found**

Key files to remember:
- Architecture doc: `/var/www/docs/tool-system-architecture.md`
- Testing plan: `/var/www/docs/tool-system-testing-plan.md`
- Migration: `database/migrations/2025_12_20_000001_create_pocket_tools_table.php`
- Model: `app/Models/PocketTool.php`
- ToolSelector: `app/Services/ToolSelector.php`
- Seeders: `database/seeders/PocketToolSeeder.php`, `database/seeders/ToolConflictSeeder.php`
