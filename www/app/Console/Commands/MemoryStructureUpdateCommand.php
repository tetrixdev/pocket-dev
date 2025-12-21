<?php

namespace App\Console\Commands;

use App\Models\MemoryEmbedding;
use App\Models\MemoryObject;
use App\Models\MemoryStructure;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MemoryStructureUpdateCommand extends Command
{
    protected $signature = 'memory:structure:update
                            {slug : The structure slug to update}
                            {--name= : New structure name}
                            {--description= : New description}
                            {--schema= : New JSON Schema (use with caution)}
                            {--icon= : New icon name}
                            {--color= : New hex color}
                            {--regenerate-embeddings : Regenerate embeddings for all objects (use after changing x-embed fields)}';

    protected $description = 'Update an existing memory structure';

    public function __construct(
        private EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

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

        $updates = [];
        $warnings = [];

        // Collect safe updates
        if ($this->option('name') !== null) {
            $updates['name'] = $this->option('name');
        }

        if ($this->option('description') !== null) {
            $updates['description'] = $this->option('description');
        }

        if ($this->option('icon') !== null) {
            $updates['icon'] = $this->option('icon');
        }

        if ($this->option('color') !== null) {
            $updates['color'] = $this->option('color');
        }

        // Handle schema updates with warnings
        if ($this->option('schema') !== null) {
            $schemaJson = $this->option('schema');
            $newSchema = json_decode($schemaJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->outputJson([
                    'output' => 'Invalid JSON in --schema: ' . json_last_error_msg(),
                    'is_error' => true,
                ]);
                return Command::FAILURE;
            }

            $objectCount = MemoryObject::where('structure_id', $structure->id)->count();

            if ($objectCount > 0) {
                // Analyze schema changes
                $analysis = $this->analyzeSchemaChanges($structure->schema, $newSchema);

                if (!empty($analysis['removed_fields'])) {
                    $warnings[] = "Removed fields: " . implode(', ', $analysis['removed_fields']) . " (data will be orphaned in existing objects)";
                }

                if (!empty($analysis['type_changes'])) {
                    $warnings[] = "Type changes: " . implode(', ', $analysis['type_changes']) . " (may cause validation issues)";
                }

                if (!empty($analysis['embed_changes'])) {
                    $warnings[] = "x-embed changes detected. Use --regenerate-embeddings to update vectors.";
                }

                $warnings[] = "{$objectCount} existing object(s) may need to be updated to match the new schema.";
            }

            $updates['schema'] = $newSchema;
        }

        if (empty($updates)) {
            $this->outputJson([
                'output' => 'No updates provided. Use --name, --description, --schema, --icon, or --color.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Perform the update
        $structure->update($updates);

        // Handle embedding regeneration if requested
        $embeddingsRegenerated = 0;
        if ($this->option('regenerate-embeddings')) {
            $embeddingsRegenerated = $this->regenerateEmbeddings($structure);
        }

        $output = "Updated structure: {$structure->name} ({$structure->slug})";

        if ($embeddingsRegenerated > 0) {
            $output .= "\nRegenerated embeddings for {$embeddingsRegenerated} object(s).";
        }

        $result = [
            'output' => $output,
            'is_error' => false,
            'structure' => [
                'id' => $structure->id,
                'name' => $structure->name,
                'slug' => $structure->slug,
                'description' => $structure->description,
            ],
            'updated_fields' => array_keys($updates),
        ];

        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }

        $this->outputJson($result);

        return Command::SUCCESS;
    }

    /**
     * Analyze changes between old and new schemas.
     */
    private function analyzeSchemaChanges(array $oldSchema, array $newSchema): array
    {
        $analysis = [
            'removed_fields' => [],
            'added_fields' => [],
            'type_changes' => [],
            'embed_changes' => false,
        ];

        $oldProperties = $oldSchema['properties'] ?? [];
        $newProperties = $newSchema['properties'] ?? [];

        // Find removed fields
        foreach ($oldProperties as $field => $def) {
            if (!isset($newProperties[$field])) {
                $analysis['removed_fields'][] = $field;
            }
        }

        // Find added fields and type changes
        foreach ($newProperties as $field => $def) {
            if (!isset($oldProperties[$field])) {
                $analysis['added_fields'][] = $field;
            } else {
                // Check for type changes
                $oldType = $oldProperties[$field]['type'] ?? null;
                $newType = $def['type'] ?? null;
                if ($oldType !== $newType) {
                    $analysis['type_changes'][] = "{$field}: {$oldType} → {$newType}";
                }

                // Check for x-embed changes
                $oldEmbed = !empty($oldProperties[$field]['x-embed']);
                $newEmbed = !empty($def['x-embed']);
                if ($oldEmbed !== $newEmbed) {
                    $analysis['embed_changes'] = true;
                }
            }
        }

        // Check for new x-embed fields
        foreach ($newProperties as $field => $def) {
            if (!empty($def['x-embed']) && !isset($oldProperties[$field])) {
                $analysis['embed_changes'] = true;
            }
        }

        return $analysis;
    }

    /**
     * Regenerate embeddings for all objects of this structure.
     */
    private function regenerateEmbeddings(MemoryStructure $structure): int
    {
        $objects = MemoryObject::where('structure_id', $structure->id)->get();
        $count = 0;

        $embeddableFields = $structure->getEmbeddableFields();

        foreach ($objects as $object) {
            DB::transaction(function () use ($object, $embeddableFields, &$count) {
                // Delete existing embeddings
                MemoryEmbedding::where('object_id', $object->id)->delete();

                // Generate new embeddings
                foreach ($embeddableFields as $fieldPath) {
                    $content = $object->data[$fieldPath] ?? null;

                    if (!empty($content) && is_string($content)) {
                        $contentHash = MemoryEmbedding::hashContent($content);
                        $embedding = $this->embeddingService->embed($content);

                        MemoryEmbedding::create([
                            'object_id' => $object->id,
                            'field_path' => $fieldPath,
                            'content_hash' => $contentHash,
                            'embedding' => $embedding,
                        ]);
                    }
                }

                $count++;
            });
        }

        return $count;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
