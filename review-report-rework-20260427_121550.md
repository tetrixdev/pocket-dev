# Code Review: feature/tool-extract-push (Re-review)

## Summary

This re-review covers the rework commit (`0115ec7`) that addressed 14 of 15 findings from the original review. The rework is thorough and well-scoped — all originally flagged issues were resolved or explicitly accepted as learnings. The new findings are consistency and robustness gaps, none of which are blocking. **Verdict: ⚠️ Needs attention (minor issues)** — eight minor items worth resolving, all straightforward fixes.

## Requirements Check

Requirements fully aligned. All six original requirements remain satisfied. The rework commit is correctly scoped to addressing prior review findings without introducing new requirements gaps. The "extras" (validation additions, parameter rename, slug mismatch warning, diagnostic output, documentation updates) are appropriate improvements driven by the previous review.

## Implicit Assumptions

1. **Temp directory persistence within a conversation**: The workflow assumes `/tmp/pocketdev/tools/{slug}/` persists for the duration of a session. Not programmatically enforced; relies on convention in AI instructions.

2. **No concurrent editing conflicts**: The extract-edit-push pattern assumes only one agent/conversation modifies a tool at a time. Last push wins. Matches pre-existing behavior.

3. **AI agent is the only consumer**: No human CLI users are expected to rely on the removed commands (`tool:create`, `tool:update`, `tool:show`).

4. **File system permissions**: Writes to `/tmp/pocketdev/tools/` and assumes write access. Enforced via `mkdir()` return value check in `ToolExtractTool`.

5. **Type change validation is partial**: When changing type via meta.json, the rework validates that the new type's required file exists either in the directory or already stored on the model, assuming the stored content is still valid.

## Previous Review Verification

### Worth Resolving — Important

| ID | Finding | Status |
|----|---------|--------|
| SEC-001 | No path validation on extract/push output directories | ✅ Addressed — Added to `docs/review-learnings.md` as accepted learning. Docker container is the security boundary. |
| UX-002 / BL-013 | Enabled and type fields silently ignored on update | ✅ Addressed — `updateTool()` now reads and applies `meta['enabled']` and `meta['type']` with validation. |
| UX-015 | Extract with custom output gives incorrect push instructions | ✅ Addressed — `ToolExtractTool` conditionally includes `--directory` in push instructions. |
| CONS-001 | System prompt references deleted commands | ✅ Addressed — `SystemPromptBuilder.php` now references `tool:push` and `dependencies.json`. |

### Worth Resolving — Minor

| ID | Finding | Status |
|----|---------|--------|
| BL-007 | Empty system_prompt.md accepted on creation | ✅ Addressed — `createTool()` now checks `empty(trim($systemPrompt))`. |
| BL-014 | input_schema.json accepts JSON scalars | ✅ Addressed — Both paths now check `!is_array()` after decode. |
| BL-015 | dependencies.json accepts JSON scalars | ✅ Addressed — Both paths now check `!is_array()` after decode. |
| BL-009 | Empty script/template accepted on update | ✅ Addressed — `updateTool()` checks `empty(trim($content))` for script.sh and template.blade.php. |
| UX-001 | "No changes detected" lacks diagnostics | ✅ Addressed — Now lists all checked files. |
| CONS-004 / ARC-010 | Inconsistent parameter naming | ✅ Addressed — Renamed `output` → `directory` in extract tool and CLI. |
| CONS-002 | Outdated PHPDoc in SystemPromptBuilder | ✅ Addressed — PHPDoc updated to reference `tool-extract` and `tool-push`. |
| ARCH-008 | Missing $apiExamples on new tool classes | ✅ Addressed — Added to `docs/review-learnings.md` as accepted learning. |

### Worth Resolving — Nitpick

| ID | Finding | Status |
|----|---------|--------|
| UX-003 | Success message uses internal field names | ✅ Addressed — Update output now uses file names. |
| UX-011 | Slug change silently discarded | ✅ Addressed — Warning added when meta.json slug differs from target. |
| UX-019 | Update success less verbose than create | ✅ Addressed — Update output now includes ID, Type, and Enabled. |

### Deferred Items

| ID | Finding | Status |
|----|---------|--------|
| EFF-004 | Repetitive update blocks | ⚠️ Not addressed (expected) — Deferred in original review. |
| EFF-003 | Duplicated file-reading patterns | ⚠️ Not addressed (expected) — Deferred in original review. |
| ARCH-006 | Duplicated outputJson method | ⚠️ Not addressed (expected) — Deferred in original review. |
| CONS-008 | Internal docs reference deleted commands | ✅ Addressed — Four documentation files updated despite being deferred. |

### Summary

- ✅ **Addressed:** 14 of 15 "Worth Resolving" findings + 1 deferred item (CONS-008)
- ⚠️ **Not addressed (expected):** 3 deferred items (EFF-004, EFF-003, ARCH-006)
- ❌ **Not addressed:** 0 findings

## Findings — Worth Resolving

### 🔴 Critical

None found.

### 🟠 Important

None found.

### 🟡 Minor

1. **BL-002 — Stale content preserved when changing tool type**

   **User Story:** When a user changes a tool's type from panel to script (or vice versa) via meta.json during a push, the old type's primary content is preserved in the database. For example, changing a panel to a script leaves the blade_template intact. If the user later runs tool:extract, both template.blade.php and script.sh are extracted even though only one corresponds to the active type. If the user then changes the type back, the old stale content silently becomes active without any review or warning.

   **Impact:** Two concrete consequences: (1) tool:extract produces a confusing directory with files for both types, making it unclear which type is active. (2) Changing the type back to the original type silently reactivates old content. The type-change validation at lines 318 and 321 checks empty() on the old content and finds the stale data, so it allows the reverse type change without requiring the user to provide a fresh file. The old content could be outdated or incompatible with current requirements. Note: there is no security risk — PanelController gates rendering with a type=panel WHERE clause, so a stale template on a script-type tool is never rendered.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 312–326)
   - `www/app/Tools/ToolExtractTool.php` (lines 119–135)

   **Proposed Solution:** When changing tool type in updateTool, null out the old type's primary content field. When changing from panel to script, set blade_template to null (and optionally panel_dependencies). When changing from script to panel, set script to null. This prevents stale content from persisting and forces the user to provide fresh content if they ever change the type back.

2. **BL-006 — Missing trim() in createTool empty-content validation**

   **User Story:** When a user creates a new tool via tool:push with a script.sh or template.blade.php file that contains only whitespace (spaces, newlines), the tool is successfully created because createTool uses `empty($script)` which treats non-empty strings (even whitespace-only) as truthy. However, if the same user later pushes the same whitespace-only content as an update, it is rejected because updateTool uses `empty(trim($content))`. This means the create and update paths enforce different validation standards for the same fields.

   **Impact:** A whitespace-only script.sh or template.blade.php can be stored during creation, producing a tool that appears valid but has a useless script or template. The same content would be rejected during an update. Multiple fact-checkers independently identified this as the same root cause (ARCH-002, SEC-007, UX-003, EFF-008 all dismissed as duplicates of BL-006).

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 546, 549 — createTool; lines 373, 387 — updateTool)

   **Proposed Solution:** Add `trim()` to the createTool checks: change `empty($script)` to `empty(trim($script))` at line 546, and `empty($bladeTemplate)` to `empty(trim($bladeTemplate))` at line 549. This aligns createTool with the existing updateTool validation.

3. **CONS-001 — Stale tool:create reference in panels.php config comment**

   **User Story:** When a developer reads the panels.php config file to understand the documentation examples section, they see a comment referencing 'tool:create system prompt' on line 102. The tool:create command was renamed to tool:push in this PR. This means the comment references a command that no longer exists, which could confuse developers trying to understand how the examples are used.

   **Impact:** A developer reading the config file will see a reference to a non-existent command. They may search for tool:create and find nothing, wasting time. The confusion is limited to this comment and does not affect runtime behavior.

   **Files:**
   - `www/config/panels.php` (lines 97–104)

   **Proposed Solution:** Change the comment on line 102 of www/config/panels.php from 'tool:create system prompt' to 'tool:push system prompt' (or 'tool creation system prompt' to be command-name-agnostic).

4. **CONS-002 — Stale ToolCreate class name in README architecture diagram**

   **User Story:** When a developer reads the Architecture Overview ASCII diagram in docs/README.md, they see 'ToolCreate' listed as a PHP Tool Class on line 90. This class was replaced by ToolExtract and ToolPush in this PR. The diagram now shows class names that do not exist in the codebase, which could mislead developers trying to understand the tool system architecture.

   **Impact:** The architecture diagram is inaccurate. A developer reading it would expect to find a ToolCreate class but would not find one, leading to confusion about the actual tool management classes. Since this is the main documentation entry point (docs/README.md), the inaccuracy is likely to be encountered.

   **Files:**
   - `www/docs/README.md` (lines 88–91)

   **Proposed Solution:** Update line 90 of www/docs/README.md to replace 'ToolCreate' with 'ToolExtract, ToolPush' (or similar) in the ASCII diagram. The line currently reads 'MemoryQuery, MemoryCreate, ToolCreate, etc.' and should be updated to reflect the actual class names.

5. **CONS-004 — Misleading "required" error message for empty files in createTool**

   **User Story:** When a user provides a script.sh or template.blade.php file that is empty (zero bytes), createTool rejects it with the message "script.sh is required for type=script" (line 547) or "template.blade.php is required for type=panel" (line 550). The word "required" implies the file is missing, but the file was provided — it's just empty. The updateTool path correctly says "must not be empty" for the same scenario.

   **Impact:** A user who provides the file but leaves it empty sees "required" and may think the file wasn't found rather than understanding it needs content. The updateTool messages at lines 374 and 388 correctly say "must not be empty", creating an inconsistency in error quality between the two paths. This was confirmed by fact-checking, which also dismissed UX-007 as a duplicate of this finding.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 546–551 — createTool; lines 373–374, 388–389 — updateTool for comparison)

   **Proposed Solution:** Differentiate "file not found" from "file is empty" in createTool error messages. If the file exists but is empty, use wording like "script.sh must not be empty for type=script" to match the updateTool pattern. If the file is truly missing, keep the "required" wording.

6. **CONS-006 — Update success message always says "tool" regardless of type**

   **User Story:** When a user updates a panel-type tool via tool:push, the success message says 'Updated tool: MyPanel (my-panel)'. But when the same user creates a panel, the success message says 'Created panel: MyPanel (my-panel)'. The create path distinguishes tools from panels in its output, but the update path always says 'tool' regardless of type.

   **Impact:** The AI agent consuming this output may momentarily doubt whether a type change to 'panel' was actually applied, since the update confirmation still says 'tool'. While the Type field in the output does show the correct value, the inconsistent headline label undermines confidence in the operation.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 438–439 — updateTool; lines 569–571 — createTool for comparison)

   **Proposed Solution:** In the updateTool method, add a dynamic type label the same way createTool does. Replace the hardcoded 'Updated tool:' string on line 439 with a type-aware label like 'Updated panel:' or 'Updated tool:' based on the tool's type. This is a one-line change: add `$typeLabel = $tool->type === PocketTool::TYPE_PANEL ? 'panel' : 'tool';` before the output array and use it in the string.

7. **CONS-007 — Missing is_array() validation for meta.json**

   **User Story:** When a user or AI agent creates a meta.json file containing a JSON scalar value (like 42 or "hello") instead of a JSON object, and then runs tool:push, the system throws an unhandled PHP TypeError instead of returning a descriptive error message. This contrasts with input_schema.json and dependencies.json in the same class, which both have explicit is_array() checks that produce user-friendly error messages for the same kind of malformed input.

   **Impact:** A JSON scalar in meta.json causes a PHP TypeError crash (500 error or CLI exception) because the decoded scalar is passed to a ?array type-hinted parameter. The sibling files (input_schema.json at line 359, dependencies.json at line 405) both gracefully reject scalars with clear error messages. This inconsistency means a developer maintaining this code might assume all JSON files have the same defensive validation, when in fact meta.json is missing it.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 264–282 — meta.json; lines 354–361 — input_schema.json; lines 400–407 — dependencies.json)

   **Proposed Solution:** Add an is_array() check after json_decode for meta.json, matching the pattern used by input_schema.json and dependencies.json. After line 271 (the json_last_error check), add: `if ($meta !== null && !is_array($meta)) { return ToolResult::error("meta.json must contain a JSON object, not a scalar value"); }`. This ensures all three JSON file validations in the class follow the same defensive pattern.

8. **SEC-003 — No slug format validation on CLI tool:push path**

   **User Story:** When a tool is created via the tool:push CLI command (used by the AI agent), the slug is accepted without format validation. The web UI path (ConfigController) validates slugs with a regex requiring only lowercase letters, numbers, and hyphens (max 64 chars), but the CLI path accepts any non-empty string. This means a tool could be created with a slug containing spaces, uppercase letters, or special characters, which could cause issues with CLI command parsing (e.g., 'pd tool:run my tool' would fail) or display problems.

   **Impact:** A tool created with an invalid slug format (e.g., containing spaces or special characters) would be stored in the database but could be unusable via CLI commands. The inconsistency between the web UI validation and the CLI tool path means the same operation has different constraints depending on the entry point. This is a robustness gap rather than a security vulnerability since the Docker container is the security boundary (per existing learning SEC-001).

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 248–257)
   - `www/app/Http/Controllers/ConfigController.php` (lines 2164–2171)

   **Proposed Solution:** Add slug format validation in ToolPushTool.php's execute() method, after the empty check on line 253. Validate that the slug matches the same regex used by ConfigController: `/^[a-z0-9-]+$/` with a max length of 64. Return an error like 'slug must contain only lowercase letters, numbers, and hyphens (max 64 characters)' if validation fails.

### ⚪ Nitpick

None found.

## Deferred Items

None.

## Proposed Learnings

1. **BL-001 — PHP (bool) cast on meta.json enabled field treats string "false" as true** (scope: `www/app/Tools/ToolPushTool.php`)

   **Finding:** PHP (bool) cast on meta.json enabled field treats string "false" as true. When `$meta['enabled']` is the JSON string `"false"`, `(bool)"false"` evaluates to `true` in PHP, so the tool would be enabled instead of disabled. The same applies to the create path where the raw value is passed to Eloquent, whose boolean cast also uses PHP's (bool).

   **Resolution:** Acceptable because `json_decode` converts JSON booleans to proper PHP booleans. The edge case requires writing invalid JSON semantics (quoting a boolean as a string). In the standard workflow, `tool:extract` always generates correct JSON booleans. Both create and update paths produce identical results since Eloquent's boolean cast also uses PHP (bool).

## Documentation Suggestions

None from fact-checkers.

## Review Metadata
- Strategy: Requirements-Driven with Specialist → Fact-Check Pipeline (v5)
- Files reviewed: 9
- Topic specialists used: 6
- Total preliminary findings (Phase 2): 46
- Files explicitly marked N/A by specialists: 48
- Groups created (Phase 3): 5
- Fact-checkers dispatched (Phase 4): 5
- Findings confirmed: 9
- Findings dismissed: 37
- Worth resolving: 8
- Deferred: 0
- Proposed learnings: 1
