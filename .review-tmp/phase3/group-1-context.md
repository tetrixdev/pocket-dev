## Group 1: Enabled field boolean casting and create/update inconsistency

### Files to Read
- www/app/Tools/ToolPushTool.php (lines ~308-310 for updateTool enabled handling, ~565 for createTool enabled handling)
- www/app/Models/PocketTool.php (line ~52 for Eloquent boolean cast definition)
- www/app/Tools/ToolExtractTool.php (line ~102 for how tool:extract writes enabled to meta.json)

### Finding Count
7 findings to verify
