<?php

namespace App\Tools;

/**
 * Edit files using exact string replacement.
 */
class EditTool extends Tool
{
    public string $name = 'Edit';

    public string $description = 'Perform exact string replacement in a file. The old_string must be unique unless using replace_all.';

    public string $category = 'file_ops';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'file_path' => [
                'type' => 'string',
                'description' => 'Path to the file to edit',
            ],
            'old_string' => [
                'type' => 'string',
                'description' => 'The exact text to find and replace',
            ],
            'new_string' => [
                'type' => 'string',
                'description' => 'The replacement text',
            ],
            'replace_all' => [
                'type' => 'boolean',
                'description' => 'Replace all occurrences instead of requiring uniqueness. Default: false',
            ],
        ],
        'required' => ['file_path', 'old_string', 'new_string'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Edit files using exact string replacement.

## Examples

```bash
# Replace a single occurrence (must be unique)
php artisan edit -- --file_path=app/Models/User.php \
  --old_string='protected $table' \
  --new_string='protected $table = "users"'

# Replace all occurrences
php artisan edit -- --file_path=app/Services/Api.php \
  --old_string='$oldVar' \
  --new_string='$newVar' \
  --replace_all=true
```

## Important
- **Always read the file first** to get exact text including whitespace
- old_string must be unique OR use `--replace_all=true`
- Preserve exact indentation from source
- Edit fails if old_string not found or appears multiple times (without replace_all)
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $filePath = $input['file_path'] ?? '';
        $oldString = $input['old_string'] ?? '';
        $newString = $input['new_string'] ?? '';
        $replaceAll = $input['replace_all'] ?? false;

        if (empty($filePath)) {
            return ToolResult::error('file_path is required');
        }

        if ($oldString === '') {
            return ToolResult::error('old_string is required');
        }

        if ($oldString === $newString) {
            return ToolResult::error('old_string and new_string are identical');
        }

        // Resolve path
        $resolvedPath = $context->resolvePath($filePath);

        if (!file_exists($resolvedPath)) {
            return ToolResult::error("File not found: {$filePath}");
        }

        // Validate path is within allowed working directory
        if (!$context->isPathAllowed($resolvedPath)) {
            return ToolResult::error("Access denied: Path is outside allowed working directory");
        }

        if (!is_writable($resolvedPath)) {
            return ToolResult::error("File is not writable: {$filePath}");
        }

        $content = file_get_contents($resolvedPath);

        if ($content === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        // Count occurrences
        $count = substr_count($content, $oldString);

        if ($count === 0) {
            return ToolResult::error("old_string not found in file. Make sure you're using the exact text including whitespace.");
        }

        if ($replaceAll) {
            // Replace all occurrences
            $newContent = str_replace($oldString, $newString, $content);
            $result = file_put_contents($resolvedPath, $newContent);

            if ($result === false) {
                return ToolResult::error("Failed to write file: {$filePath}");
            }

            return ToolResult::success("Replaced {$count} occurrence(s)");
        }

        // Single replacement - must be unique
        if ($count > 1) {
            return ToolResult::error(
                "old_string appears {$count} times in the file. " .
                "Provide more surrounding context to make it unique, or use replace_all: true"
            );
        }

        // Single unique replacement
        $pos = strpos($content, $oldString);
        $newContent = substr_replace($content, $newString, $pos, strlen($oldString));

        $result = file_put_contents($resolvedPath, $newContent);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        return ToolResult::success("File updated successfully");
    }
}
