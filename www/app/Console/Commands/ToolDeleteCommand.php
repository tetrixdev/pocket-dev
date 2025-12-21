<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolDeleteCommand extends Command
{
    protected $signature = 'tool:delete
        {slug : The slug of the tool to delete}';

    protected $description = 'Delete a user tool';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        if ($tool->isPocketdev()) {
            return $this->outputError("Cannot delete PocketDev tool '{$slug}'. Only user-created tools can be deleted.");
        }

        $name = $tool->name;

        try {
            $tool->delete();

            $this->outputResult([
                'output' => "Deleted tool: {$name} ({$slug})",
                'is_error' => false,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to delete tool: ' . $e->getMessage());
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
