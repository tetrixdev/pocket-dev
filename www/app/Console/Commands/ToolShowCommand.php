<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolShowCommand extends Command
{
    protected $signature = 'tool:show
        {slug : The slug of the tool to show}
        {--script : Include the full script in output}';

    protected $description = 'Show details of a specific tool';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $showScript = $this->option('script');

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        $output = [
            'output' => $this->formatToolDetails($tool, $showScript),
            'is_error' => false,
            'tool' => [
                'id' => $tool->id,
                'slug' => $tool->slug,
                'name' => $tool->name,
                'description' => $tool->description,
                'system_prompt' => $tool->system_prompt,
                'source' => $tool->source,
                'enabled' => $tool->enabled,
                'category' => $tool->category,
                'input_schema' => $tool->input_schema,
                'has_script' => $tool->hasScript(),
            ],
        ];

        if ($showScript && $tool->hasScript()) {
            $output['tool']['script'] = $tool->script;
        }

        $this->output->writeln(json_encode($output, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function formatToolDetails(PocketTool $tool, bool $showScript): string
    {
        $lines = [
            "Tool: {$tool->name}",
            "Slug: {$tool->slug}",
            "ID: {$tool->id}",
            "",
            "Source: " . $tool->source,
            "Status: " . ($tool->enabled ? 'Enabled' : 'Disabled'),
            "Category: {$tool->category}",
            "",
            "Description:",
            "  {$tool->description}",
            "",
            "System Prompt:",
            "  " . str_replace("\n", "\n  ", $tool->system_prompt),
        ];

        if ($tool->input_schema) {
            $lines[] = "";
            $lines[] = "Input Schema:";
            $lines[] = "  " . json_encode($tool->input_schema, JSON_PRETTY_PRINT);
        }

        if ($tool->isPocketdev()) {
            $command = $tool->getArtisanCommand();
            if ($command) {
                $lines[] = "";
                $lines[] = "Artisan Command: php artisan {$command}";
            }
        }

        if ($showScript && $tool->hasScript()) {
            $lines[] = "";
            $lines[] = "Script:";
            $lines[] = "---";
            $lines[] = $tool->script;
            $lines[] = "---";
        }

        return implode("\n", $lines);
    }

    private function outputError(string $message): int
    {
        $this->output->writeln(json_encode([
            'output' => $message,
            'is_error' => true,
        ], JSON_PRETTY_PRINT));

        return Command::FAILURE;
    }
}
