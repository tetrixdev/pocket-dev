<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolPushTool;
use Illuminate\Console\Command;

class ToolPushCommand extends Command
{
    protected $signature = 'tool:push
        {slug : The slug of the tool to push}
        {--directory= : Directory containing tool files (default: /tmp/pocketdev/tools/{slug}/)}';

    protected $description = 'Push a tool from a local directory to PocketDev (create or update)';

    public function handle(): int
    {
        $tool = new ToolPushTool();

        $input = [
            'slug' => $this->argument('slug'),
        ];

        if ($this->option('directory') !== null) {
            $input['directory'] = $this->option('directory');
        }

        $context = new ExecutionContext(getcwd() ?: '/var/www');
        $result = $tool->execute($input, $context);

        $this->outputJson($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
