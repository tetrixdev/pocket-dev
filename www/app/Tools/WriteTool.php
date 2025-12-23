<?php

namespace App\Tools;

/**
 * Write or create files.
 */
class WriteTool extends Tool
{
    public string $name = 'Write';

    public string $description = 'Write content to a file. Creates the file if it does not exist, overwrites if it does.';

    public string $category = 'file_ops';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'file_path' => [
                'type' => 'string',
                'description' => 'Path to the file to write',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'The content to write to the file',
            ],
        ],
        'required' => ['file_path', 'content'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Write or create files.

## Examples

```bash
# Create a new file
php artisan write --file_path=config/custom.php --content='<?php return [];'

# Write multi-line content (use $'...' for newlines)
php artisan write --file_path=README.md --content=$'# Project\n\nDescription here.'
```

## Notes
- Creates parent directories automatically if needed
- Prefer Edit tool for modifying existing files
- Overwrites existing files without warning
- Paths outside working directory are blocked
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $filePath = $input['file_path'] ?? '';
        $content = $input['content'] ?? '';

        if (empty($filePath)) {
            return ToolResult::error('file_path is required');
        }

        // Resolve path
        $resolvedPath = $context->resolvePath($filePath);

        // Validate path structure is within allowed working directory
        // (uses isPathStructureAllowed since file may not exist yet)
        if (!$context->isPathStructureAllowed($resolvedPath)) {
            return ToolResult::error("Access denied: Path is outside allowed working directory");
        }

        // Get parent directory for subsequent checks
        $parentDir = dirname($resolvedPath);

        // Create parent directories if needed
        if (!is_dir($parentDir)) {
            $created = @mkdir($parentDir, 0755, true);
            if (!$created) {
                return ToolResult::error("Failed to create directory: {$parentDir}");
            }
        }

        // Check if file exists and is writable, or if directory is writable
        if (file_exists($resolvedPath) && !is_writable($resolvedPath)) {
            return ToolResult::error("File exists but is not writable: {$filePath}");
        }

        if (!file_exists($resolvedPath) && !is_writable($parentDir)) {
            return ToolResult::error("Directory is not writable: {$parentDir}");
        }

        // Check if file is new BEFORE writing
        $isNew = !file_exists($resolvedPath);

        // Write file
        $result = file_put_contents($resolvedPath, $content);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        $bytes = strlen($content);
        $lines = substr_count($content, "\n") + 1;

        $action = $isNew ? 'Created' : 'Wrote';

        return ToolResult::success("{$action} {$bytes} bytes ({$lines} lines) to {$filePath}");
    }
}
