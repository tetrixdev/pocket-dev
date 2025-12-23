<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolShowTool;
use Illuminate\Console\Command;

class ToolShowCommand extends Command
{
    protected $signature = 'tool:show
        {slug : The slug of the tool to show}
        {--script : Include the full script in output}';

    protected $description = 'Show details of a specific tool';

    public function handle(): int
    {
        $tool = new ToolShowTool();

        $input = [
            'slug' => $this->argument('slug'),
        ];

        if ($this->option('script')) {
            $input['include_script'] = true;
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
