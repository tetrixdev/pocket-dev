<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolExtractTool;
use Illuminate\Console\Command;

class ToolExtractCommand extends Command
{
    protected $signature = 'tool:extract
        {slug : The slug of the tool to extract}
        {--output= : Output directory path (default: /tmp/pocketdev/tools/{slug}/)}';

    protected $description = 'Extract a tool to a local directory for editing';

    public function handle(): int
    {
        $tool = new ToolExtractTool();

        $input = [
            'slug' => $this->argument('slug'),
        ];

        if ($this->option('output') !== null) {
            $input['output'] = $this->option('output');
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
