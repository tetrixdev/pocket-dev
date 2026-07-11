## Group 6: Structural concerns, slug validation, stale references, and ToolExtractTool

### Files to Read
- www/app/Tools/ToolPushTool.php (overall structure of updateTool and createTool methods)
- www/app/Tools/ToolExtractTool.php (line ~148 for $isCustomDirectory; $apiExamples property check)
- www/config/panels.php (line ~102 for stale 'tool:create' reference)
- www/docs/README.md (line ~90 for stale 'ToolCreate' reference in diagram)
- docs/review-learnings.md (ARCH-008 for $apiExamples accepted finding)
- www/app/Services/SystemPromptBuilder.php (for how slug is used in system prompt)

### Finding Count
10 findings to verify
