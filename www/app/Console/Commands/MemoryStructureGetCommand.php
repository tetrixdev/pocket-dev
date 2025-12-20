<?php

namespace App\Console\Commands;

use App\Models\MemoryStructure;
use Illuminate\Console\Command;

class MemoryStructureGetCommand extends Command
{
    protected $signature = 'memory:structure:get
                            {slug : The structure slug to retrieve}';

    protected $description = 'Get a memory structure schema by slug';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $structure = MemoryStructure::where('slug', $slug)->first();

        if (!$structure) {
            $this->outputJson([
                'output' => "Structure '{$slug}' not found",
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        $this->outputJson([
            'output' => "Structure: {$structure->name} ({$structure->slug})",
            'is_error' => false,
            'structure' => [
                'id' => $structure->id,
                'name' => $structure->name,
                'slug' => $structure->slug,
                'description' => $structure->description,
                'schema' => $structure->schema,
                'icon' => $structure->icon,
                'color' => $structure->color,
            ],
        ]);

        return Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
