<?php

namespace App\Tools;

use App\Models\Credential;
use Illuminate\Support\Facades\Process;

/**
 * Execute bash commands.
 */
class BashTool extends Tool
{
    public string $name = 'Bash';

    public string $description = 'Execute a bash command. Use for git, npm, docker, and other terminal operations.';

    public string $category = 'file_ops';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'command' => [
                'type' => 'string',
                'description' => 'The bash command to execute',
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => 'Timeout in seconds. Default: 120, Max: 600',
            ],
        ],
        'required' => ['command'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Execute bash commands for git, npm, docker, and terminal operations.

## Examples

```bash
# Git operations
php artisan bash --command="git status"
php artisan bash --command="git log --oneline -10"

# Package management
php artisan bash --command="composer install"
php artisan bash --command="npm run build"

# With timeout for long operations
php artisan bash --command="npm install" --timeout=300
```

## Notes
- Use for terminal operations only (git, npm, docker, etc.)
- Do NOT use for file operations - use Read, Edit, Write instead
- Commands run in the working directory
- Output truncated at 30000 characters
- Default timeout: 120s, max: 600s
INSTRUCTIONS;

    /** Commands that are blocked for security */
    private array $blockedPatterns = [
        'rm -rf /',
        'rm -rf /*',
        'mkfs',
        ':(){:|:&};:',  // Fork bomb
        '> /dev/sda',
        'dd if=/dev/zero',
        'chmod -R 777 /',
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $command = $input['command'] ?? '';
        $timeout = min(600, max(1, $input['timeout'] ?? 120));

        if (empty($command)) {
            return ToolResult::error('command is required');
        }

        // Security check
        foreach ($this->blockedPatterns as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return ToolResult::error('Command blocked for security reasons');
            }
        }

        // Get credentials as environment variables (workspace-specific override global)
        $workspace = $context->getWorkspace();
        $workspaceId = $workspace?->id;
        $credentials = Credential::getEnvArrayForWorkspace($workspaceId);

        // Execute command with credentials injected
        $result = Process::timeout($timeout)
            ->env($credentials)
            ->path($context->getWorkingDirectory())
            ->run($command);

        $stdout = $result->output();
        $stderr = $result->errorOutput();
        $exitCode = $result->exitCode();

        // Combine output
        $output = '';

        if (!empty($stdout)) {
            $output .= $stdout;
        }

        if (!empty($stderr)) {
            if (!empty($output)) {
                $output .= "\n";
            }
            $output .= $stderr;
        }

        // Truncate if too long
        $maxLength = config('ai.tools.max_output_length', 30000);
        if (strlen($output) > $maxLength) {
            $output = substr($output, 0, $maxLength) . "\n\n[Output truncated at {$maxLength} characters]";
        }

        if (empty($output)) {
            $output = '(no output)';
        }

        if ($exitCode === 0) {
            return ToolResult::success($output);
        }

        return ToolResult::error("Exit code {$exitCode}:\n{$output}");
    }
}
