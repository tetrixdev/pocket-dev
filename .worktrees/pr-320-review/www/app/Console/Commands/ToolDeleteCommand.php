<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolDeleteTool;
use Illuminate\Console\Command;

class ToolDeleteCommand extends Command
{
    protected $signature = 'tool:delete
        {slug : The slug of the tool to delete}';

    protected $description = 'Delete a user tool';

    public function handle(): int
    {
        $tool = new ToolDeleteTool();

        $input = [
            'slug' => $this->argument('slug'),
        ];

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
