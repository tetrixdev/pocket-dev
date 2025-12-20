<?php

namespace App\Console\Commands;

use App\Models\MemoryObject;
use App\Models\MemoryStructure;
use Illuminate\Console\Command;

class MemoryStructureDeleteCommand extends Command
{
    protected $signature = 'memory:structure:delete
                            {slug : The structure slug to delete}';

    protected $description = 'Delete a memory structure (only if no objects exist)';

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

        // Check if any objects exist for this structure
        $objectCount = MemoryObject::where('structure_id', $structure->id)->count();

        if ($objectCount > 0) {
            $this->outputJson([
                'output' => "Cannot delete structure '{$slug}': {$objectCount} object(s) still exist. Delete all objects first.",
                'is_error' => true,
                'object_count' => $objectCount,
            ]);
            return Command::FAILURE;
        }

        $name = $structure->name;
        $structure->delete();

        $this->outputJson([
            'output' => "Deleted structure: {$name} ({$slug})",
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
