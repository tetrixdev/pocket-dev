<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolEnableCommand extends Command
{
    protected $signature = 'tool:enable {slug : The slug of the tool to enable}';

    protected $description = 'Enable a tool (adds its instructions to the AI system prompt)';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        if ($tool->enabled) {
            $this->outputResult([
                'output' => "Tool '{$tool->name}' is already enabled",
                'is_error' => false,
            ]);
            return Command::SUCCESS;
        }

        try {
            $tool->enabled = true;
            $tool->save();

            $this->outputResult([
                'output' => "Enabled tool: {$tool->name} ({$slug})",
                'is_error' => false,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to enable tool: ' . $e->getMessage());
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
