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

    private ?EmbeddingService $embeddingService = null;

    private function embeddingService(): EmbeddingService
    {
        return $this->embeddingService ??= app(EmbeddingService::class);
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

            // Validate it's a proper JSON Schema structure
            if (!isset($newSchema['type']) || !isset($newSchema['properties'])) {
                $this->outputJson([
                    'output' => 'Invalid JSON Schema: must include "type" and "properties"',
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

                if (!empty($analysis['has_nested_schemas'])) {
                    $warnings[] = "Nested object schemas detected. Changes to nested properties are not fully tracked - consider using --regenerate-embeddings if nested x-embed fields were modified.";
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
            'has_nested_schemas' => false,
        ];

        $oldProperties = $oldSchema['properties'] ?? [];
        $newProperties = $newSchema['properties'] ?? [];

        // Check for nested object schemas (properties with type: "object" and their own properties)
        foreach (array_merge($oldProperties, $newProperties) as $field => $def) {
            if (($def['type'] ?? null) === 'object' && isset($def['properties'])) {
                $analysis['has_nested_schemas'] = true;
                break;
            }
        }

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
     *
     * Embeddings are generated outside the transaction to avoid holding
     * DB locks during API calls. Only the delete + insert operations
     * are wrapped in a transaction.
     */
    private function regenerateEmbeddings(MemoryStructure $structure): int
    {
        $count = 0;
        $embeddableFields = $structure->getEmbeddableFields();

        MemoryObject::where('structure_id', $structure->id)
            ->chunk(100, function ($objects) use ($embeddableFields, &$count) {
                foreach ($objects as $object) {
                    // 1. Generate embeddings OUTSIDE transaction (API calls)
                    $newEmbeddings = [];
                    foreach ($embeddableFields as $fieldPath) {
                        $content = $object->getField($fieldPath);

                        if (!empty($content) && is_string($content)) {
                            $contentHash = MemoryEmbedding::hashContent($content);
                            $embedding = $this->embeddingService()->embed($content);

                            if ($embedding === null) {
                                $this->warn("Failed to generate embedding for field '{$fieldPath}' in object {$object->id}. Skipping.");
                                continue;
                            }

                            $newEmbeddings[] = [
                                'field_path' => $fieldPath,
                                'content_hash' => $contentHash,
                                'embedding' => $embedding,
                            ];
                        }
                    }

                    // 2. Database operations INSIDE transaction (quick)
                    if (!empty($newEmbeddings)) {
                        DB::transaction(function () use ($object, $newEmbeddings) {
                            // Delete existing embeddings
                            MemoryEmbedding::where('object_id', $object->id)->delete();

                            // Insert new embeddings
                            foreach ($newEmbeddings as $embeddingData) {
                                MemoryEmbedding::create([
                                    'object_id' => $object->id,
                                    'field_path' => $embeddingData['field_path'],
                                    'content_hash' => $embeddingData['content_hash'],
                                    'embedding' => $embeddingData['embedding'],
                                ]);
                            }
                        });
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
