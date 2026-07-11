<?php

namespace App\Tools;

/**
 * Read file contents with optional line range.
 */
class ReadTool extends Tool
{
    public string $name = 'Read';

    public string $description = 'Read the contents of a file. Returns file content with line numbers.';

    public string $category = 'file_ops';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'file_path' => [
                'type' => 'string',
                'description' => 'Path to the file to read (absolute or relative to working directory)',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Line number to start reading from (1-indexed). Default: 1',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of lines to read. Default: 2000',
            ],
        ],
        'required' => ['file_path'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Read file contents with line numbers.

## Examples

```bash
# Read entire file
pd read --file_path=app/Models/User.php

# Read specific lines (lines 50-100)
pd read --file_path=app/Models/User.php --offset=50 --limit=50
```

## Notes
- Always read a file before editing it
- Line numbers in output can be used for Edit tool reference
- Files > 10MB require using offset/limit
- Default limit is 2000 lines, max is 5000
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $filePath = $input['file_path'] ?? '';
        $offset = max(1, $input['offset'] ?? 1);
        $limit = min(5000, max(1, $input['limit'] ?? 2000));

        if (empty($filePath)) {
            return ToolResult::error('file_path is required');
        }

        // Resolve path relative to working directory
        $resolvedPath = $context->resolvePath($filePath);

        // Validate path is within allowed working directory
        if (!$context->isPathAllowed($resolvedPath)) {
            return ToolResult::error("Access denied: Path is outside allowed working directory");
        }

        if (!file_exists($resolvedPath)) {
            return ToolResult::error("File not found: {$filePath}");
        }

        if (!is_readable($resolvedPath)) {
            return ToolResult::error("File is not readable: {$filePath}");
        }

        if (is_dir($resolvedPath)) {
            return ToolResult::error("Path is a directory, not a file: {$filePath}");
        }

        // Check file size to avoid memory issues
        $fileSize = filesize($resolvedPath);
        if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
            return ToolResult::error("File too large (> 10MB). Use offset/limit for large files.");
        }

        $lines = file($resolvedPath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        $totalLines = count($lines);

        if ($offset > $totalLines) {
            return ToolResult::error("Offset {$offset} exceeds file length ({$totalLines} lines)");
        }

        // Apply offset (convert to 0-indexed)
        $startIndex = $offset - 1;
        $selectedLines = array_slice($lines, $startIndex, $limit);

        // Format with line numbers
        $output = [];
        foreach ($selectedLines as $i => $line) {
            $lineNum = $startIndex + $i + 1;
            // Truncate very long lines
            if (strlen($line) > 2000) {
                $line = substr($line, 0, 2000) . '... [truncated]';
            }
            $output[] = sprintf('%6d\t%s', $lineNum, $line);
        }

        $result = implode("\n", $output);

        // Add metadata if not showing full file
        if ($startIndex > 0 || $startIndex + count($selectedLines) < $totalLines) {
            $showing = count($selectedLines);
            $result .= "\n\n[Showing lines {$offset}-" . ($offset + $showing - 1) . " of {$totalLines}]";
        }

        return ToolResult::success($result);
    }
}
