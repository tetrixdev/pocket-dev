<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ToolRunCommand extends Command
{
    protected $signature = 'tool:run
        {slug : The slug of the tool to run}
        {arguments?* : Arguments to pass to the tool script (positional or --name=value)}';

    protected $description = 'Run a user tool';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $arguments = $this->argument('arguments') ?? [];

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        if ($tool->isPocketdev()) {
            // For PocketDev tools, show the artisan command instead
            $command = $tool->getArtisanCommand();
            if ($command) {
                return $this->outputError("'{$slug}' is a PocketDev tool. Use: php artisan {$command}");
            }
            return $this->outputError("'{$slug}' is a PocketDev tool and cannot be run directly");
        }

        if (!$tool->hasScript()) {
            return $this->outputError("Tool '{$slug}' has no script defined");
        }

        // Parse named and positional arguments
        [$namedArgs, $positionalArgs] = $this->parseArguments($arguments);

        // If tool has input_schema, validate and use named args
        $envVars = [];
        if ($tool->input_schema && !empty($tool->input_schema['properties'])) {
            $schema = $tool->input_schema;
            $required = $schema['required'] ?? [];

            // Check required parameters
            foreach ($required as $param) {
                if (!isset($namedArgs[$param])) {
                    return $this->outputError("Missing required parameter: --{$param}");
                }
            }

            // Build environment variables from named args (uppercase with TOOL_ prefix)
            foreach ($namedArgs as $name => $value) {
                $envName = 'TOOL_' . strtoupper(str_replace('-', '_', $name));
                $envVars[$envName] = $value;
            }
        }

        $tempFile = null;
        try {
            // Create temp script file
            $tempFile = tempnam(sys_get_temp_dir(), 'pocket_tool_');
            if ($tempFile === false) {
                return $this->outputError('Failed to create temporary script file');
            }
            file_put_contents($tempFile, $tool->script);
            chmod($tempFile, 0755);

            // Build command with positional arguments (for backward compatibility)
            $escapedArgs = array_map('escapeshellarg', $positionalArgs);
            $command = $tempFile . ' ' . implode(' ', $escapedArgs);

            // Execute the script with environment variables
            $result = Process::timeout(300)->env($envVars)->run($command);

            $output = $result->output();
            $errorOutput = $result->errorOutput();

            if ($result->failed()) {
                $message = $errorOutput ?: $output ?: 'Script execution failed with exit code ' . $result->exitCode();
                return $this->outputError($message);
            }

            // Try to parse output as JSON, otherwise return as plain text
            $parsedOutput = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsedOutput)) {
                // Output is already JSON
                $this->output->writeln($output);
            } else {
                $this->outputResult([
                    'output' => trim($output),
                    'is_error' => false,
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to run tool: ' . $e->getMessage());
        } finally {
            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Parse arguments into named (--key=value) and positional args.
     *
     * @return array{0: array<string, string>, 1: array<string>}
     */
    private function parseArguments(array $arguments): array
    {
        $named = [];
        $positional = [];

        $i = 0;
        while ($i < count($arguments)) {
            $arg = $arguments[$i];

            if (str_starts_with($arg, '--')) {
                // Named argument
                if (str_contains($arg, '=')) {
                    // --name=value format
                    [$key, $value] = explode('=', substr($arg, 2), 2);
                    $named[$key] = $value;
                } else {
                    // --name value format
                    $key = substr($arg, 2);
                    $i++;
                    $named[$key] = $arguments[$i] ?? '';
                }
            } else {
                // Positional argument
                $positional[] = $arg;
            }
            $i++;
        }

        return [$named, $positional];
    }

    private function outputError(string $message): int
    {
        $this->output->writeln(json_encode([
            'output' => $message,
            'is_error' => true,
        ], JSON_PRETTY_PRINT));

        return Command::FAILURE;
    }

    private function outputResult(array $result): void
    {
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));
    }
}
