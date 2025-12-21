<?php

namespace App\Console\Commands;

use App\Models\MemoryStructure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MemoryStructureCreateCommand extends Command
{
    protected $signature = 'memory:structure:create
                            {--name= : Structure name (e.g., "Character")}
                            {--slug= : URL-friendly slug (auto-generated from name if omitted)}
                            {--description= : What this structure represents}
                            {--schema= : JSON Schema defining the structure fields}
                            {--icon= : Icon name (optional)}
                            {--color= : Hex color (optional, e.g., "#2196F3")}';

    protected $description = 'Create a new memory structure (schema/template for memory objects)';

    public function handle(): int
    {
        $name = $this->option('name');
        $slug = $this->option('slug') ?: Str::slug($name);
        $description = $this->option('description') ?: '';
        $schemaJson = $this->option('schema');
        $icon = $this->option('icon');
        $color = $this->option('color');

        // Validate required fields
        if (empty($name)) {
            $this->outputJson([
                'output' => 'Missing required option: --name',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        if (empty($schemaJson)) {
            $this->outputJson([
                'output' => 'Missing required option: --schema (JSON Schema)',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Parse and validate schema JSON
        $schema = json_decode($schemaJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->outputJson([
                'output' => 'Invalid JSON in --schema: ' . json_last_error_msg(),
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Check if slug already exists
        if (MemoryStructure::where('slug', $slug)->exists()) {
            $this->outputJson([
                'output' => "Structure with slug '{$slug}' already exists",
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Create the structure
        $structure = MemoryStructure::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'schema' => $schema,
            'icon' => $icon,
            'color' => $color,
        ]);

        $this->outputJson([
            'output' => "Created structure: {$name} ({$slug})\nID: {$structure->id}",
            'is_error' => false,
            'structure' => [
                'id' => $structure->id,
                'name' => $structure->name,
                'slug' => $structure->slug,
                'description' => $structure->description,
            ],
        ]);

        return Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
