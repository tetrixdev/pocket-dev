<?php

namespace App\Tools;

use Illuminate\Support\Facades\Process;

/**
 * Execute bash commands.
 */
class BashTool extends Tool
{
    public string $name = 'Bash';

    public string $description = 'Execute a bash command. Use for git, npm, docker, and other terminal operations.';

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
- Use for git, npm, docker, and terminal operations
- Do NOT use for file operations - use Read, Edit, Write instead
- Commands run in the working directory
- Output is truncated at 30000 characters
- Default timeout is 120 seconds (max 600)
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

        // Execute command
        $result = Process::timeout($timeout)
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
