<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolListCommand extends Command
{
    protected $signature = 'tool:list
        {--enabled : Show only enabled tools}
        {--disabled : Show only disabled tools}
        {--pocketdev : Show only PocketDev tools}
        {--user : Show only user tools}
        {--category= : Filter by category}
        {--provider= : Filter by provider availability}
        {--json : Output as JSON array}';

    protected $description = 'List all tools';

    public function handle(): int
    {
        $query = PocketTool::query();

        if ($this->option('enabled')) {
            $query->where('enabled', true);
        }

        if ($this->option('disabled')) {
            $query->where('enabled', false);
        }

        if ($this->option('pocketdev')) {
            $query->pocketdev();
        }

        if ($this->option('user')) {
            $query->user();
        }

        if ($this->option('category')) {
            $query->category($this->option('category'));
        }

        if ($this->option('provider')) {
            $query->forProvider($this->option('provider'));
        }

        $tools = $query->orderBy('category')->orderBy('name')->get();

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'output' => "Found {$tools->count()} tool(s)",
                'is_error' => false,
                'tools' => $tools->map(fn($t) => [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'description' => $t->description,
                    'source' => $t->source,
                    'category' => $t->category,
                    'capability' => $t->capability,
                    'enabled' => $t->enabled,
                    'excluded_providers' => $t->excluded_providers,
                    'native_equivalent' => $t->native_equivalent,
                ])->toArray(),
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if ($tools->isEmpty()) {
            $this->outputResult([
                'output' => 'No tools found',
                'is_error' => false,
            ]);
            return Command::SUCCESS;
        }

        $output = ["Found {$tools->count()} tool(s):", ""];

        $currentCategory = null;
        foreach ($tools as $tool) {
            if ($tool->category !== $currentCategory) {
                if ($currentCategory !== null) {
                    $output[] = "";
                }
                $output[] = "[{$tool->category}]";
                $currentCategory = $tool->category;
            }

            $status = $tool->enabled ? '+' : '-';
            $source = $tool->source;
            $excluded = $tool->excluded_providers ? ' (excluded: ' . implode(',', $tool->excluded_providers) . ')' : '';
            $output[] = "  {$status} {$tool->slug} [{$source}]{$excluded}";
            $output[] = "    {$tool->description}";
        }

        $output[] = "";
        $output[] = "Legend: + enabled, - disabled";
        $output[] = "Sources: pocketdev = core PocketDev tools, user = custom tools";

        $this->outputResult([
            'output' => implode("\n", $output),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function outputResult(array $result): void
    {
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));
    }
}
