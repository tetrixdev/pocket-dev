<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\PanelPeekTool;
use Illuminate\Console\Command;

class PanelPeekCommand extends Command
{
    protected $signature = 'panel:peek
        {panel_slug : The slug of the panel type to peek at}
        {--id= : Optional panel state ID (short form or full UUID)}';

    protected $description = 'Peek at the current visible state of an open panel';

    public function handle(): int
    {
        $tool = new PanelPeekTool();

        $input = [
            'panel_slug' => $this->argument('panel_slug'),
        ];

        if ($id = $this->option('id')) {
            $input['id'] = $id;
        }

        $context = new ExecutionContext(getcwd() ?: '/var/www');
        $result = $tool->execute($input, $context);

        if ($result->isError()) {
            $this->outputJson($result->toArray());
            return Command::FAILURE;
        }

        // For peek, output the content directly (it's markdown)
        $this->output->writeln($result->output);

        return Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
