# System Prompt Optimization Tracker

---

## Cross-Cutting Concerns

### Meta-Instructions (How AI writes for future AI)
- [ ] Define where this guidance lives
- [ ] Tool system_prompts guidance
- [ ] Table descriptions guidance
- [ ] Column descriptions guidance
- [ ] TEXT field markdown templates

### Duplication to Eliminate
- [ ] `--schema` parameter explanation (7 tools)
- [ ] Schema naming convention (5+ places)
- [ ] Auto-embedding behavior (3 tools)
- [ ] System tables explanation (2+ places)

### Implementation Tasks
- [ ] Add `CATEGORY_CONVERSATION` constant
- [ ] Update `config/tool-groups.php` with new groups
- [ ] Move shared content to group-level prompts
- [ ] Trim individual tool instructions

---

## System Prompt Hierarchy

- [ ] **System Prompt** — `SystemPromptBuilder.php`
  - **Status:** Structure not yet finalized
  - **Tasks:** Review assembly order, finalize sections
  - **Comments:** _None yet_

  - [ ] **1. Core Prompt** — `resources/defaults/system-prompt.md`
    - **Status:** Not reviewed
    - **Tasks:** Review content, check if meta-instructions belong here
    - **Comments:** _None yet_

  - [ ] **2. Tools** — `$instructions` + `config/tool-groups.php`
    - **Status:** Decided: keep fine-grained, improve grouping
    - **Tasks:** Finalize group structure, define shared prompts, trim tool instructions
    - **Comments:** Split Memory Data into Read/Write. Conversation needs new category.

    - [ ] **2.1 Memory Data (Read)** — group prompt + tool instructions
      - **Status:** Not started
      - **Tasks:** Define shared read prompt (query patterns, semantic search)
      - **Comments:** _None yet_
      - **Tools:**
        - [ ] `memory:query` — `MemoryQueryTool.php`

    - [ ] **2.2 Memory Data (Write)** — group prompt + tool instructions
      - **Status:** Not started
      - **Tasks:** Define shared write prompt (templates, read-before-write, auto-embed)
      - **Comments:** _None yet_
      - **Tools:**
        - [ ] `memory:insert` — `MemoryInsertTool.php`
        - [ ] `memory:update` — `MemoryUpdateTool.php`
        - [ ] `memory:delete` — `MemoryDeleteTool.php`

    - [ ] **2.3 Memory Schema** — group prompt + tool instructions
      - **Status:** Not started
      - **Tasks:** Define shared schema prompt (column types, embed_fields)
      - **Comments:** _None yet_
      - **Tools:**
        - [ ] `memory:schema:create-table` — `MemorySchemaCreateTableTool.php`
        - [ ] `memory:schema:execute` — `MemorySchemaExecuteTool.php`
        - [ ] `memory:schema:list-tables` — `MemorySchemaListTablesTool.php`

    - [ ] **2.4 Conversation** — group prompt + tool instructions
      - **Status:** Needs `CATEGORY_CONVERSATION` constant
      - **Tasks:** Create category, define shared prompt (semantic search)
      - **Comments:** Currently under `memory_data`, doesn't belong there
      - **Tools:**
        - [ ] `conversation:search` — `ConversationSearchTool.php`
        - [ ] `conversation:get-turns` — `ConversationGetTurnsTool.php`

    - [ ] **2.5 Tool Management** — group prompt + tool instructions
      - **Status:** Not started
      - **Tasks:** Define shared prompt (meta-instructions for system_prompts)
      - **Comments:** _None yet_
      - **Tools:**
        - [ ] `tool:create` — `ToolCreateTool.php`
        - [ ] `tool:update` — `ToolUpdateTool.php`
        - [ ] `tool:delete` — `ToolDeleteTool.php`
        - [ ] `tool:list` — `ToolListTool.php`
        - [ ] `tool:show` — `ToolShowTool.php`
        - [ ] `tool:run` — `ToolRunTool.php`

    - [ ] **2.6 System** — group prompt + tool instructions
      - **Status:** Grouping undecided
      - **Tasks:** Decide: own group or part of another?
      - **Comments:** _None yet_
      - **Tools:**
        - [ ] `system:package` — `SystemPackageTool.php`

    - [ ] **2.7 File Ops (API only)** — group prompt + tool instructions
      - **Status:** Not started
      - **Tasks:** Review all tool instructions
      - **Comments:** Only loads for API providers
      - **Tools:**
        - [ ] `read` — `ReadTool.php`
        - [ ] `write` — `WriteTool.php`
        - [ ] `edit` — `EditTool.php`
        - [ ] `glob` — `GlobTool.php`
        - [ ] `grep` — `GrepTool.php`
        - [ ] `bash` — `BashTool.php`

    - [ ] **2.8 Custom Tools** — `pocket_tools` table + `UserTool.php`
      - **Status:** Not started
      - **Tasks:** Review loading mechanism, ensure meta-instructions exist
      - **Comments:** _None yet_
      - **Tools:** _(user-created, dynamic)_

  - [ ] **3. Memory** — `schema_registry` + PostgreSQL metadata
    - **Status:** Not started
    - **Tasks:** Review intro text, decide template guidance location
    - **Comments:** _None yet_

    - [ ] **3.1 Intro Text** — hardcoded in `ToolSelector.php`
      - **Status:** Not reviewed
      - **Tasks:** Review text, decide where it should live
      - **Comments:** _None yet_

    - [ ] **3.2 Per-Schema Info** — `MemoryDatabase` model
      - **Status:** Not reviewed
      - **Tasks:** Review what's shown per schema
      - **Comments:** _None yet_

    - [ ] **3.3 Per-Table** — `schema_registry.description` + columns
      - **Status:** Not reviewed
      - **Tasks:** Define good description format, column format, example inserts?
      - **Comments:** _None yet_

  - [ ] **4. Additional Prompt** — `storage/pocketdev/additional-system-prompt.md`
    - **Status:** Not reviewed
    - **Tasks:** Review purpose, document what belongs here
    - **Comments:** _None yet_

  - [ ] **5. Agent Instructions** — `agents.system_prompt` (database)
    - **Status:** Not reviewed
    - **Tasks:** Review loading, document what belongs here
    - **Comments:** _None yet_

  - [ ] **6. Working Directory** — dynamic from conversation
    - **Status:** Not reviewed
    - **Tasks:** Review format, any improvements?
    - **Comments:** _None yet_

  - [ ] **7. Environment** — credentials + packages
    - **Status:** Not reviewed
    - **Tasks:** Review formats
    - **Comments:** _None yet_

    - [ ] **7.1 Credentials** — `Credential` model
      - **Status:** Not reviewed
      - **Tasks:** Review format (names only)
      - **Comments:** _None yet_

    - [ ] **7.2 Packages** — `SystemPackage` model
      - **Status:** Not reviewed
      - **Tasks:** Review format (CLI commands)
      - **Comments:** _None yet_

  - [ ] **8. Context Usage** — dynamic calculation
    - **Status:** Not reviewed
    - **Tasks:** Review format, any improvements?
    - **Comments:** _None yet_

---

## Related Documents

- [Full Proposal](./system-prompt-optimization.md)
- `app/Services/SystemPromptBuilder.php`
- `config/tool-groups.php`
