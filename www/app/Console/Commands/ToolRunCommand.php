<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ToolRunCommand extends Command
{
    protected $signature = 'tool:run
        {slug : The slug of the tool to run}
        {arguments?* : Arguments to pass to the tool script}';

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

        try {
            // Create temp script file
            $tempFile = tempnam(sys_get_temp_dir(), 'pocket_tool_');
            file_put_contents($tempFile, $tool->script);
            chmod($tempFile, 0755);

            // Build command with arguments
            $escapedArgs = array_map('escapeshellarg', $arguments);
            $command = $tempFile . ' ' . implode(' ', $escapedArgs);

            // Execute the script
            $result = Process::timeout(300)->run($command);

            // Clean up temp file
            unlink($tempFile);

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
        }
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
