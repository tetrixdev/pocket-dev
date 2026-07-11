<?php

namespace App\Console\Commands;

use App\Tools\ComposeTransformTool;
use App\Tools\ExecutionContext;
use Illuminate\Console\Command;

class ComposeTransformCommand extends Command
{
    protected $signature = 'compose:transform
        {--input= : Path to the compose file (required)}';

    protected $description = 'Transform Docker Compose files to use PocketDev workspace volume mounts';

    public function handle(): int
    {
        $input = $this->option('input');

        if (empty($input)) {
            $this->outputJson([
                'output' => 'The --input option is required. Specify the path to the compose file.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        $tool = new ComposeTransformTool();
        $context = new ExecutionContext(getcwd());
        $result = $tool->execute(['input' => $input], $context);

        // The tool returns JSON, so we can output it directly
        $this->output->writeln($result->getOutput());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
