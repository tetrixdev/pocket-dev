<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Tools\ExecutionContext;
use App\Tools\ToolRunTool;
use Illuminate\Console\Command;

class ToolRunCommand extends Command
{
    protected $signature = 'tool:run
        {slug : The slug of the tool to run}
        {arguments?* : Arguments to pass to the tool script (--name=value format)}
        {--session= : Session ID for panel tools (auto-detected from POCKETDEV_SESSION_ID env var if not provided)}';

    protected $description = 'Run a user tool (script or panel)';

    public function handle(): int
    {
        $tool = new ToolRunTool();

        // Parse CLI arguments into tool arguments
        $arguments = $this->parseArguments($this->argument('arguments') ?? []);

        $input = [
            'slug' => $this->argument('slug'),
            'arguments' => $arguments,
        ];

        // Get session if provided (needed for panel tools)
        // Priority: 1) --session option, 2) POCKETDEV_SESSION_ID env var (set by CLI providers)
        $session = null;
        $sessionId = $this->option('session');

        if (! $sessionId) {
            $sessionId = getenv('POCKETDEV_SESSION_ID') ?: null;
        }

        if ($sessionId) {
            $session = Session::find($sessionId);
            if (! $session) {
                $this->outputJson([
                    'output' => "Session not found: {$sessionId}",
                    'is_error' => true,
                ]);

                return Command::FAILURE;
            }
        }

        $context = new ExecutionContext(
            getcwd() ?: '/var/www',
            session: $session
        );
        $result = $tool->execute($input, $context);

        $this->outputJson($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Parse CLI arguments (--name=value format) into a key-value array.
     */
    private function parseArguments(array $arguments): array
    {
        $named = [];

        $i = 0;
        while ($i < count($arguments)) {
            $arg = $arguments[$i];

            if (str_starts_with($arg, '--')) {
                if (str_contains($arg, '=')) {
                    // --name=value format
                    [$key, $value] = explode('=', substr($arg, 2), 2);
                    $named[$key] = $value;
                } else {
                    // --name value format
                    $key = substr($arg, 2);
                    $i++;
                    if (!isset($arguments[$i])) {
                        // Trailing --param without value treated as boolean flag
                        $named[$key] = 'true';
                    } else {
                        $named[$key] = $arguments[$i];
                    }
                }
            }
            $i++;
        }

        return $named;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
