<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolListTool;
use Illuminate\Console\Command;

class ToolListCommand extends Command
{
    protected $signature = 'tool:list
        {--enabled : Show only enabled tools}
        {--disabled : Show only disabled tools}
        {--pocketdev : Show only PocketDev tools}
        {--user : Show only user tools}
        {--category= : Filter by category}
        {--provider= : Filter by provider availability}';

    protected $description = 'List all tools';

    public function handle(): int
    {
        $tool = new ToolListTool();

        // Build input from options
        $input = [];

        if ($this->option('enabled')) {
            $input['enabled'] = true;
        }

        if ($this->option('disabled')) {
            $input['disabled'] = true;
        }

        if ($this->option('pocketdev')) {
            $input['pocketdev'] = true;
        }

        if ($this->option('user')) {
            $input['user'] = true;
        }

        if ($this->option('category') !== null) {
            $input['category'] = $this->option('category');
        }

        if ($this->option('provider') !== null) {
            $input['provider'] = $this->option('provider');
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
