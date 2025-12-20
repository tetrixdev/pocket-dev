<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolDisableCommand extends Command
{
    protected $signature = 'tool:disable {slug : The slug of the tool to disable}';

    protected $description = 'Disable a tool (removes its instructions from the AI system prompt)';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        if (!$tool->enabled) {
            $this->outputResult([
                'output' => "Tool '{$tool->name}' is already disabled",
                'is_error' => false,
            ]);
            return Command::SUCCESS;
        }

        try {
            $tool->enabled = false;
            $tool->save();

            $this->outputResult([
                'output' => "Disabled tool: {$tool->name} ({$slug})",
                'is_error' => false,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to disable tool: ' . $e->getMessage());
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
