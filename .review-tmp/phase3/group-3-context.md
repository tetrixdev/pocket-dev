## Group 3: JSON validation for input_schema, dependencies, and meta

### Files to Read
- www/app/Tools/ToolPushTool.php (lines ~354-361 and ~400-407 for updateTool JSON validation, ~522-542 for createTool JSON validation, ~266-272 for meta.json decode)
- www/app/Http/Controllers/PanelController.php (buildPanelHeadTags() for how dependencies are rendered)
- www/app/Services/SystemPromptBuilder.php (for how input_schema is used downstream)

### Finding Count
6 findings to verify
