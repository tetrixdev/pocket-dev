## Group 2: Type change handling and stale data preservation

### Files to Read
- www/app/Tools/ToolPushTool.php (lines ~312-326 for type change logic, ~324 for type assignment timing)
- www/app/Tools/ToolExtractTool.php (to check what tool:extract outputs for a tool that changed types)
- www/app/Http/Controllers/PanelController.php (to check if rendering path only uses type=panel tools)
- www/app/Models/PocketTool.php (hasScript() and hasBladeTemplate() methods)

### Finding Count
5 findings to verify
