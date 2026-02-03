<?php

namespace App\Tools;

use Illuminate\Support\Facades\Process;

/**
 * Search for patterns in files using ripgrep.
 */
class GrepTool extends Tool
{
    public string $name = 'Grep';

    public string $description = 'Search for patterns in files using regex. Uses ripgrep for fast searching.';

    public string $category = 'file_ops';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'Regex pattern to search for',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Directory or file to search in. Defaults to working directory.',
            ],
            'glob' => [
                'type' => 'string',
                'description' => 'File pattern filter (e.g., "*.php", "*.{ts,tsx}")',
            ],
            'output_mode' => [
                'type' => 'string',
                'enum' => ['content', 'files_with_matches', 'count'],
                'description' => 'Output format: content (matching lines), files_with_matches (file paths only), count (match counts). Default: files_with_matches',
            ],
            'case_insensitive' => [
                'type' => 'boolean',
                'description' => 'Case insensitive search. Default: false',
            ],
            'context_lines' => [
                'type' => 'integer',
                'description' => 'Number of context lines before and after matches. Only for content mode.',
            ],
        ],
        'required' => ['pattern'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Search for patterns in files using regex (powered by ripgrep).

## Examples

```bash
# Find files containing "TODO"
pd grep --pattern="TODO"

# Search in PHP files only, show matching lines
pd grep --pattern="function.*execute" --glob="*.php" --output_mode=content

# Case-insensitive search with context
pd grep --pattern="error" --case_insensitive=true --output_mode=content --context_lines=2

# Count matches per file
pd grep --pattern="import" --glob="*.ts" --output_mode=count
```

## Output Modes
- `files_with_matches` (default) - just file paths
- `content` - matching lines with line numbers
- `count` - number of matches per file

## Notes
- Uses ripgrep for fast searching
- Pattern is a regex (e.g., `function\s+\w+` for function definitions)
- Output is truncated if too long
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $pattern = $input['pattern'] ?? '';
        $path = $input['path'] ?? null;
        $glob = $input['glob'] ?? null;
        $outputMode = $input['output_mode'] ?? 'files_with_matches';
        $caseInsensitive = $input['case_insensitive'] ?? false;
        $contextLines = $input['context_lines'] ?? null;

        if (empty($pattern)) {
            return ToolResult::error('pattern is required');
        }

        // Build ripgrep command
        $args = ['rg'];

        // Output mode
        switch ($outputMode) {
            case 'files_with_matches':
                $args[] = '-l';
                break;
            case 'count':
                $args[] = '-c';
                break;
            case 'content':
                $args[] = '-n'; // Line numbers
                if ($contextLines !== null && $contextLines > 0) {
                    $args[] = '-C';
                    $args[] = (string) min(10, $contextLines);
                }
                break;
        }

        // Case insensitive
        if ($caseInsensitive) {
            $args[] = '-i';
        }

        // Glob filter
        if ($glob !== null) {
            $args[] = '--glob';
            $args[] = $glob;
        }

        // Pattern
        $args[] = $pattern;

        // Path
        $searchPath = $path !== null
            ? $context->resolvePath($path)
            : $context->getWorkingDirectory();

        if (!$context->isPathAllowed($searchPath)) {
            return ToolResult::error("Access denied: Path is outside allowed working directory");
        }

        $args[] = $searchPath;

        // Execute
        $result = Process::timeout(60)
            ->run($args);

        $output = $result->output();
        $exitCode = $result->exitCode();

        // ripgrep exit codes:
        // 0 = matches found
        // 1 = no matches
        // 2 = error

        if ($exitCode === 2) {
            $stderr = $result->errorOutput();

            return ToolResult::error("Search failed: {$stderr}");
        }

        if ($exitCode === 1 || empty(trim($output))) {
            return ToolResult::success('No matches found');
        }

        // Truncate if too long
        $maxLength = config('ai.tools.max_output_length', 30000);
        if (strlen($output) > $maxLength) {
            $output = substr($output, 0, $maxLength) . "\n\n[Output truncated]";
        }

        return ToolResult::success($output);
    }
}
