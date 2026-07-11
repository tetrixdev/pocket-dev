# Code Review: feature/tool-extract-push

## Summary

This PR refactors the tool management system from three separate commands (`tool:create`, `tool:update`, `tool:show`) into a streamlined extract/push workflow (`tool:extract`, `tool:push`). The implementation cleanly satisfies all six original requirements and the code is well-structured. However, there are several validation gaps where protections from the old code were not carried over to the new code, an important path-safety concern in the extract tool, and stale references to removed commands in runtime output and documentation.

**Verdict: :warning: Needs attention** -- no blocking issues, but the validation gaps and the stale system prompt output referencing deleted commands should be addressed before merging.

## Requirements Check

All six requirements are fully aligned with the implementation:

1. **Extract existing tools** to a local temp directory as separate files -- implemented via `ToolExtractTool`
2. **Create new tools** in the same directory structure -- implemented via `ToolPushTool`'s create path
3. **Push changes back** with validation and error feedback -- comprehensive validation in `ToolPushTool`
4. **AI instructions say to extract before editing** -- explicitly stated in tool instructions
5. **Remove tool:create, tool:update, tool:show** -- all three tools and their commands fully deleted
6. **Enable surgical edits** via Edit tool -- achieved by extracting to individual files

**Secondary gaps noted:**
- Multiple documentation files still reference removed commands (`tool:create`, `tool:update`, `tool:show`)
- `SystemPromptBuilder.php` line 506 produces **runtime output** referencing `tool:create`/`tool:update` -- this is user-facing
- `SystemPromptBuilder.php` line 470 has an outdated docblock comment

## Implicit Assumptions

1. **Temp directory persistence within a conversation**: The workflow assumes `/tmp/pocketdev/tools/{slug}/` persists for the duration of a conversation session. Not enforced between sessions, but instructions tell the AI to re-extract at the start of each conversation.

2. **No concurrent editing conflicts**: The extract-edit-push pattern assumes only one agent/conversation modifies a tool at a time. Last push wins. This matches the old behavior (no regression).

3. **AI agent is the only consumer**: The requirement states "only AI uses them" -- no human CLI users are expected to rely on the removed commands.

4. **File system permissions**: The tool writes to `/tmp/pocketdev/tools/` and assumes write access. Enforced: `ToolExtractTool` checks `mkdir()` return value.

5. **Slug consistency**: The slug in `meta.json` could differ from the slug argument passed to `tool:push`. For creation, the argument takes precedence. For updates, the `meta.json` slug is not cross-validated.

## Findings -- Worth Resolving

### :red_circle: Critical

None found.

### :orange_circle: Important

1. **SEC-001 -- No path validation on extract/push output directories**

   **User Story:** When an AI agent processes a conversation, it can invoke the ToolExtract tool with an arbitrary 'output' parameter. The output parameter is used directly in `mkdir()` and `file_put_contents()` calls without any path validation or sandboxing. Although the `ExecutionContext` class has an `isPathAllowed()` method, it is deprecated and always returns true. This means the AI could be prompted (e.g., via prompt injection in user-supplied content) to extract tool files to any writable location on the filesystem, such as configuration directories or cron directories.

   **Impact:** An attacker who can influence the AI's tool calls (via prompt injection in conversation content or tool outputs) could direct file writes to sensitive locations within the Docker container. The files written contain tool metadata (JSON), system prompts (markdown), and shell scripts from the database. While the content is not directly attacker-controlled and the Docker container limits blast radius, writing a shell script to a location that gets executed could lead to code execution. The deprecated-but-disabled path validation suggests this was a known concern that lost its protection.

   **Files:**
   - `www/app/Tools/ToolExtractTool.php` (lines 71-91, 104-135)
   - `www/app/Tools/ExecutionContext.php` (lines 93-100)
   - `www/app/Jobs/ProcessConversationStream.php` (lines 803-808)

   **Proposed Solution:** Add path validation to `ToolExtractTool.execute()` to ensure the output directory is within an allowed base path (e.g., `/tmp/pocketdev/`). A simple check like verifying that the resolved real path starts with `/tmp/pocketdev/` would prevent path traversal. Alternatively, re-enable and fix the `isPathAllowed()` method in `ExecutionContext` rather than keeping it deprecated. The same validation should be applied to `ToolPushTool`'s 'directory' parameter.

2. **UX-002 / BL-013 -- Enabled and type fields in meta.json silently ignored on update** *(confirmed by multiple fact-checkers)*

   **User Story:** When a user runs `tool:extract` to download a tool, they receive a `meta.json` file containing all tool metadata including 'type' (script vs panel) and 'enabled' (true/false). The user edits `meta.json` to change the type from 'script' to 'panel' or toggles enabled from true to false, then runs `tool:push` to apply their changes. The push completes successfully but the type and enabled changes are silently ignored -- only name, description, and category are read from `meta.json` during updates. The user believes their changes took effect but nothing changed.

   **Impact:** Users who rely on the extract-edit-push workflow to change a tool's type or enabled status will be silently misled. There is no error, warning, or indication that these fields are not processed. This is especially problematic because there is no separate CLI command to toggle a custom tool's enabled/disabled state, making it impossible to disable a custom tool via the CLI at all. The create path does honor both fields, making the asymmetry especially confusing.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 285-307)
   - `www/app/Tools/ToolExtractTool.php` (lines 95-105)

   **Proposed Solution:** In the `updateTool()` method, read `meta['type']` and `meta['enabled']` from `meta.json` and apply them to the tool model, similar to how name, description, and category are handled. For type changes, add validation that the new type's required file exists (e.g., if changing to panel, `template.blade.php` must be present). At minimum, if not implementing type/enabled updates, emit a warning when these fields differ from the current tool values so users know their edits were not applied.

3. **UX-015 -- Extract with custom output gives incorrect push instructions**

   **User Story:** When a user extracts a tool to a custom directory using `pd tool:extract my-tool --output=/custom/path`, the success message says `push back with: pd tool:push my-tool` without mentioning the `--directory` flag. If the user follows this instruction, the push command will look in the default directory `/tmp/pocketdev/tools/my-tool/` instead of `/custom/path`, and will either fail (directory not found) or pick up stale/wrong files from a previous extraction.

   **Impact:** Users who use a custom output directory during extraction will get incorrect push instructions. Following the suggested command will either produce an error or silently push the wrong files, potentially overwriting tool changes with outdated content.

   **Files:**
   - `www/app/Tools/ToolExtractTool.php` (lines 137-150)

   **Proposed Solution:** In `ToolExtractTool::execute()`, when a custom output directory was provided (i.e., the 'output' input parameter was explicitly set), include the `--directory` flag in the push-back instruction. For example: `pd tool:push my-tool --directory=/custom/path`. When using the default directory, the current message is fine since the push tool uses the same default.

4. **CONS-001 -- System prompt references deleted tool:create and tool:update commands**

   **User Story:** When an AI agent is creating or updating a panel tool, the system prompt instructs it to use 'panel_dependencies' in 'tool:create' or 'tool:update'. These commands no longer exist -- they were replaced by 'tool:extract' and 'tool:push'. The AI will attempt to run the non-existent commands, producing errors that confuse the user.

   **Impact:** The AI will give incorrect instructions referencing deleted commands whenever it tries to add CDN dependencies to a panel. Users will see command-not-found errors and have no guidance on the correct replacement commands. This directly affects the panel authoring workflow.

   **Files:**
   - `www/app/Services/SystemPromptBuilder.php` (lines 504-509)

   **Proposed Solution:** On line 506 of `SystemPromptBuilder.php`, change the string from `'Panels can load extra CDN libraries via panel_dependencies in tool:create or tool:update.'` to `'Panels can load extra CDN libraries via panel_dependencies in tool:push.'` (or `'in the extracted dependencies.json file, then pushed via tool:push'` for more accuracy with the new extract/push workflow).

### :yellow_circle: Minor

1. **BL-007 -- Empty system_prompt.md accepted on tool creation**

   **User Story:** When a user creates a new tool via `tool:push` with a `system_prompt.md` file that exists but is empty (0 bytes), the tool is created with a blank `system_prompt`. The old `tool:create` command rejected empty system prompts. This means the tool will have no AI instructions, rendering it functionally useless -- the AI will not know how to use or describe the tool.

   **Impact:** A tool created with an empty `system_prompt` will not have any AI instructions injected into context. The AI will see the tool's name and description but have no guidance on when or how to use it. While unlikely to happen by accident, the old code explicitly guarded against this, and the guard was lost in the migration.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 406-412)

   **Proposed Solution:** Add a validation check in the `createTool` method after reading `system_prompt.md`. After the line that reads the file with `file_get_contents`, add a check: if the content is empty or whitespace-only, return an error like `'system_prompt.md must not be empty'`. This matches the behavior of the old `ToolCreateTool` which checked `empty($systemPrompt)`.

2. **BL-014 -- input_schema.json accepts JSON scalars instead of requiring object**

   **User Story:** When a user creates a tool via `tool:push` and their `input_schema.json` file contains a valid JSON scalar (like the number 42 or a plain string) instead of a JSON object, the value passes the `json_decode` success check and is stored directly into the database. The old `tool:create` command explicitly checked that `input_schema` was an array. This means the tool's parameter schema would be stored as a non-object value, which would cause errors anywhere the schema is used as an associative array.

   **Impact:** If `input_schema.json` contains a JSON scalar, the tool would be created with an invalid schema that is not a JSON object. Downstream code that accesses `input_schema` properties (e.g., `PanelRegistry::allAvailable` which reads `input_schema['properties']`) would fail or return unexpected results.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 441-449)

   **Proposed Solution:** After `json_decode` succeeds for `input_schema.json`, add a type check: `if ($inputSchema !== null && !is_array($inputSchema))`, return an error stating that `input_schema.json` must contain a JSON object. This restores the validation that existed in the old `ToolCreateTool`.

3. **BL-015 -- dependencies.json accepts JSON scalars instead of requiring array**

   **User Story:** When a user creates a tool via `tool:push` and their `dependencies.json` file contains a valid JSON scalar (like a string or number) instead of a JSON array of dependency objects, the value passes the `json_decode` success check and is stored directly into the database. The old `tool:create` command explicitly checked that `panel_dependencies` was an array.

   **Impact:** If `dependencies.json` contains a JSON scalar, the `panel_dependencies` field would be set to a non-array value. The `PanelController::wrapPanelHtml` method type-hints the parameter as array, and `buildPanelHeadTags` iterates over it with `foreach`. A non-array value would cause a PHP TypeError at render time, breaking the panel.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 451-459)

   **Proposed Solution:** After `json_decode` succeeds for `dependencies.json`, add a type check: `if ($panelDependencies !== null && !is_array($panelDependencies))`, return an error stating that `dependencies.json` must contain a JSON array. This restores the validation from the old `ToolCreateTool`.

4. **BL-009 -- Empty script/template accepted on update path**

   **User Story:** When a user extracts a script-type tool, accidentally clears the contents of `script.sh` (e.g., saves an empty file), and then runs `tool:push`, the empty script content overwrites the tool's existing working script. The tool now has an empty script and will fail when invoked. The same applies to panel-type tools where `template.blade.php` is emptied. The push succeeds with no warning about the empty content.

   **Impact:** A tool that was previously functional becomes broken with no warning. The `createTool` path validates that script-type tools have a non-empty script and panel-type tools have a non-empty `blade_template`, but the `updateTool` path lacks this validation. This is an asymmetry between create and update validation.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 343-365, 461-467)

   **Proposed Solution:** Add validation in `updateTool()` that checks whether a `script.sh` file being pushed is empty for script-type tools, and whether `template.blade.php` is empty for panel-type tools. Either reject the push with an error message, or at minimum emit a warning. The validation should mirror the checks already present in `createTool()` at lines 462-467.

5. **UX-001 -- "No changes detected" message lacks diagnostic context**

   **User Story:** When a user runs `tool:push` after editing files in the tool directory, but the file contents happen to be identical to what is already stored in the database (or the user edited the wrong files), the command returns `'No changes detected for tool-name (slug)'` with no additional context. The user does not know which files were checked, whether the directory had the expected files, or what might have gone wrong.

   **Impact:** Users who expect their edits to take effect have no diagnostic path forward. They cannot tell whether they edited the right files, whether the files were found, or whether they are pointing to the correct directory. This is especially confusing for first-time users of the extract/push workflow.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 367-369)

   **Proposed Solution:** Enhance the no-changes message to list which files were found and checked in the directory (e.g., `'Checked: meta.json, system_prompt.md, script.sh -- all match current values'`). This gives the user confidence that the right files were examined and helps them identify if expected files are missing.

6. **CONS-004 / ARCH-010 -- Inconsistent parameter naming between extract and push** *(confirmed by multiple fact-checkers)*

   **User Story:** When a user extracts a tool with `pd tool:extract --output=/path` and then pushes with `pd tool:push --directory=/path`, they must remember two different flag names for the same concept (the local directory path). These tools are designed as a complementary pair, and the inconsistent naming creates unnecessary cognitive load. The inconsistency extends to the underlying Tool classes where `ToolExtractTool` uses 'output' and `ToolPushTool` uses 'directory' in their input schemas.

   **Impact:** Users may try `--output` on the push command or `--directory` on the extract command, getting errors. The inconsistency also makes it harder to script these commands together since the flag names differ for the same parameter.

   **Files:**
   - `www/app/Tools/ToolExtractTool.php` (lines 18-31)
   - `www/app/Tools/ToolPushTool.php` (lines 19-32)
   - `www/app/Console/Commands/ToolExtractCommand.php` (lines 11-13)
   - `www/app/Console/Commands/ToolPushCommand.php` (lines 11-13)

   **Proposed Solution:** Standardize on one parameter name for both tools. `directory` is the more descriptive choice since both parameters represent a directory path. Rename `ToolExtractTool`'s 'output' parameter and the corresponding CLI `--output` flag to 'directory' / `--directory` to match `ToolPushTool`.

7. **CONS-002 -- Outdated PHPDoc comment in SystemPromptBuilder**

   **User Story:** When a developer reads the `buildPanelDependenciesSection` method in `SystemPromptBuilder.php`, the PHPDoc comment on line 470 says the section is included when 'tool-create or tool-update' is allowed. However, the actual code on line 477 checks for 'tool-extract or tool-push'. This mismatch could mislead a developer maintaining this code.

   **Impact:** A developer modifying this method could be misled by the outdated PHPDoc comment, potentially introducing bugs if they rely on the comment rather than reading the code. The inner comment on line 475 was correctly updated, making the inconsistency more confusing.

   **Files:**
   - `www/app/Services/SystemPromptBuilder.php` (lines 467-477)

   **Proposed Solution:** Update the PHPDoc comment on line 470 from `'Only included if tool-create or tool-update is allowed (or all tools allowed).'` to `'Only included if tool-extract or tool-push is allowed (or all tools allowed).'`

8. **ARCH-008 -- Missing $apiExamples property on new tool classes**

   **User Story:** When `ToolExtractTool` or `ToolPushTool` is used through the API (non-CLI) provider, the system appends `apiExamples` to the tool instructions in the AI's context. Since these two tools lack the `$apiExamples` property that every other tool class defines, API-based consumers receive tool instructions without usage examples, while all other tools include them.

   **Impact:** API consumers (as opposed to CLI users) receive incomplete documentation for these two tools. The instructions property is extensive enough that functionality is not broken, but the inconsistency means the AI gets less guidance on how to format JSON inputs for these tools compared to all others.

   **Files:**
   - `www/app/Tools/ToolExtractTool.php` (lines 10-16)
   - `www/app/Tools/ToolPushTool.php` (lines 11-16)

   **Proposed Solution:** Add `$apiExamples` properties to both `ToolExtractTool` and `ToolPushTool`, following the same heredoc pattern used by `ToolDeleteTool`, `ToolListTool`, and `ToolRunTool`. Include a JSON example showing the `tool_use` input format with slug and optional directory/output parameters.

### :white_circle: Nitpick

1. **UX-003 -- Success message uses internal field names instead of file names**

   **User Story:** When a user edits `template.blade.php` and `system_prompt.md` in the tool directory and runs `tool:push`, the success message says `'Changed: blade_template, system_prompt'` using internal database column names. The user thinks in terms of file names (`template.blade.php`, `system_prompt.md`) because that is what they just edited, creating a minor cognitive mismatch.

   **Impact:** Users must mentally map between file names they edited and internal field names in the output. While not blocking, it creates a small usability friction. The mapping is straightforward for most fields but `blade_template` vs `template.blade.php` is the most jarring mismatch.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 295-306, 374-378)

   **Proposed Solution:** Use the file names (`system_prompt.md`, `template.blade.php`, `script.sh`, `input_schema.json`, `dependencies.json`, `meta.json`) in the `$changes` array instead of the database column names. This aligns the output with the file-based mental model of the extract/push workflow.

2. **UX-011 -- Slug change in meta.json silently discarded**

   **User Story:** When a user extracts a tool, changes the slug field in `meta.json` to a different value, and runs `pd tool:push original-slug`, the push uses the command argument `original-slug` for the database lookup and ignores the slug in `meta.json` entirely. The user's slug change is silently discarded with no warning.

   **Impact:** Users who expect to rename a tool's slug by editing `meta.json` will be confused when the rename does not take effect. The slug in `meta.json` is purely informational during updates. This is arguably correct behavior (slugs are identifiers, not editable fields), but the silent discard could confuse users.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 248-283, 294-307)

   **Proposed Solution:** Add a check in `updateTool()` that compares `meta['slug']` (if present) against the tool's actual slug. If they differ, emit a warning in the output: `'Note: slug in meta.json differs from the target tool slug. Slug changes are not supported via push.'`

3. **UX-019 -- Update success message less verbose than create success message**

   **User Story:** When a user creates a new tool via `tool:push`, the success message includes the tool ID, type, and enabled status. When a user updates an existing tool, the success message only includes the tool name, slug, and a list of changed fields. A user performing an update cannot verify the tool ID or confirm whether the tool is still enabled without running a separate command.

   **Impact:** Minor inconsistency in output verbosity between create and update paths. Users performing updates get less confirmation information than users creating tools. This is a cosmetic difference that does not affect functionality.

   **Files:**
   - `www/app/Tools/ToolPushTool.php` (lines 374-380, 485-494)

   **Proposed Solution:** Add the tool ID, type, and enabled status to the update success message to match the create success message format.

## Deferred Items

1. **EFF-004 -- Repetitive update blocks in updateTool method**

   **Finding:** The `updateTool` method contains six structurally identical blocks that each follow the same pattern: check if file exists, read file content, compare to current model value, set the property if different, and append the field name to a changes array. This repetition spans about 60 lines that could be reduced to roughly 20 lines with a configuration-driven approach.

   **Defer Reason:** This is a code style improvement that does not affect functionality. It can be addressed in a follow-up cleanup PR after the core extract/push workflow is stable.

   **Proposed Solution (when addressed):** Refactor the file-to-property mapping into a configuration array (e.g., mapping file names to model properties and optional validators like JSON decode). Loop over this array to apply updates, reducing repetition and ensuring consistent handling.

   No existing tracking issue found -- consider creating one.

2. **EFF-003 -- Duplicated file-reading patterns across create and update paths**

   **Finding:** The repeated file-reading pattern (5 JSON decode+validate blocks and 6 text-file reads) totals roughly 50 lines of duplicated logic. While each instance currently works correctly, the duplication creates a maintenance risk: a bug fix or improvement to the pattern would need to be applied in up to 11 locations.

   **Defer Reason:** This is a code quality improvement that does not affect functionality. The current code works correctly and the refactoring could be done in a follow-up cleanup PR without blocking this feature.

   **Proposed Solution (when addressed):** Extract two private helper methods: `readJsonFile(string $path): ?array` (handles `file_exists`, `file_get_contents`, `json_decode`, `json_last_error` check) and `readTextFile(string $path): ?string` (handles `file_exists` + `file_get_contents`). Use these in `execute()`, `updateTool()`, and `createTool()` to replace the repeated blocks.

   No existing tracking issue found -- consider creating one.

3. **ARCH-006 -- Duplicated outputJson method across 10+ command classes**

   **Finding:** The identical 3-line `outputJson` method is duplicated across 10 command files (5 Tool*Command files plus ServerCommand, SystemPackageCommand, ServerAppCommand, PanelPeekCommand, and ComposeTransformCommand). The two new commands added in this PR (`ToolExtractCommand` and `ToolPushCommand`) perpetuate the existing pattern.

   **Defer Reason:** This is a pre-existing pattern across the codebase affecting 10+ command files, not introduced by this PR. Fixing it requires a broader refactoring effort across all affected commands.

   **Proposed Solution (when addressed):** Extract the `outputJson` method into a shared trait (e.g., `OutputsJson`) or a base command class that all JSON-outputting commands extend.

   No existing tracking issue found -- consider creating one.

4. **CONS-008 -- Internal documentation references deleted commands**

   **Finding:** Multiple documentation files reference `tool:create`, `tool:update`, and `tool:show` commands that no longer exist. The testing plan references deleted commands in multiple test cases (SHOW-001 through SHOW-003, CRUD-001 through CRUD-003, PROT-001), making it unusable for verifying the tool management system. The architecture doc lists deleted tools in the 'Tool Management' table.

   **Defer Reason:** These are internal planning and architecture documents that predate this PR. Updating them is a separate documentation task that should not block the code change from merging.

   **Proposed Solution (when addressed):** Update all documentation files to replace references to `tool:create`/`tool:update`/`tool:show` with `tool:extract`/`tool:push`. The architecture doc's Tool Management table should list Tool Extract, Tool Push, Tool Delete, Tool List, and Tool Run.

   **Files:**
   - `www/docs/tool-system-architecture.md` (lines 114-121, 401-408)
   - `www/docs/tool-system-testing-plan.md` (lines 56-72, 78-79, 141, 208-219)
   - `docs/plans/panels-and-screens.md` (lines 931-936, 1335-1355)
   - `docs/proposals/system-prompt-optimization-tracker.md` (lines 83-88)

   No existing tracking issue found -- consider creating one.

## Proposed Learnings

None.

## Documentation Suggestions

1. The `ToolPushTool` instructions heredoc documents the directory structure and `meta.json` fields but does not mention the 'enabled' field. Adding a note that `meta.json` supports an optional 'enabled' field (default: true) would help users who want to create tools in a disabled state.

2. The `ToolPushTool` instructions document that `meta.json` contains 'slug, name, description, type, category' as required fields, but do not clarify which of these fields are actually applied during updates vs creates. Adding a note like 'During updates, only name, description, and category from meta.json are applied' would prevent user confusion.

3. The `SystemPromptBuilder` still references the old 'tool:create' and 'tool:update' commands in the panel dependencies documentation section (line 506). This should be updated to reference 'tool:extract' and 'tool:push' to match the new workflow.

4. `www/docs/tool-system-architecture.md` needs a full update to reflect the `tool:extract`/`tool:push` workflow replacing `tool:create`/`tool:update`/`tool:show`.

5. `www/docs/tool-system-testing-plan.md` needs updated test cases for the new `tool:extract` and `tool:push` commands.

## Review Metadata

- Strategy: Requirements-Driven with Specialist -> Fact-Check Pipeline (v5)
- Files reviewed: 11
- Topic specialists used: 6
- Total preliminary findings (Phase 2): 82
- Files explicitly marked N/A by specialists: 41
- Groups created (Phase 3): 8
- Fact-checkers dispatched (Phase 4): 8
- Findings confirmed: 19 (after deduplication; 21 raw, 2 duplicate pairs merged)
- Findings dismissed: 61
- Worth resolving: 15
- Deferred: 4
- Proposed learnings: 0
