<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Models\MemoryRelationship;

/**
 * Remove a relationship between two memory objects.
 */
class MemoryUnlinkTool extends Tool
{
    public string $name = 'MemoryUnlink';

    public string $description = 'Remove a relationship between two memory objects.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'source_id' => [
                'type' => 'string',
                'description' => 'UUID of the source object.',
            ],
            'target_id' => [
                'type' => 'string',
                'description' => 'UUID of the target object.',
            ],
            'relationship_type' => [
                'type' => 'string',
                'description' => 'Type of relationship to remove. If not specified, removes all relationships between the objects.',
            ],
            'bidirectional' => [
                'type' => 'boolean',
                'description' => 'If true, also remove the inverse relationship. Default: false.',
            ],
        ],
        'required' => ['source_id', 'target_id'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryUnlink to remove relationships between objects.

## Examples

Remove specific relationship:
```json
{
  "source_id": "character-uuid",
  "target_id": "item-uuid",
  "relationship_type": "owns"
}
```

Remove all relationships between objects:
```json
{
  "source_id": "char-a-uuid",
  "target_id": "char-b-uuid"
}
```

Remove bidirectional:
```json
{
  "source_id": "char-a-uuid",
  "target_id": "char-b-uuid",
  "relationship_type": "knows",
  "bidirectional": true
}
```

## Notes
- Omit relationship_type to remove ALL relationships between objects
- Use bidirectional to remove inverse relationships too
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $sourceId = $input['source_id'] ?? '';
        $targetId = $input['target_id'] ?? '';
        $relationshipType = $input['relationship_type'] ?? null;
        $bidirectional = $input['bidirectional'] ?? false;

        if (empty($sourceId)) {
            return ToolResult::error('source_id is required');
        }
        if (empty($targetId)) {
            return ToolResult::error('target_id is required');
        }

        // Get object names for output
        $source = MemoryObject::find($sourceId);
        $target = MemoryObject::find($targetId);

        $sourceName = $source?->name ?? $sourceId;
        $targetName = $target?->name ?? $targetId;

        try {
            $removed = [];

            // Build query for source -> target
            $query = MemoryRelationship::where('source_id', $sourceId)
                ->where('target_id', $targetId);

            if ($relationshipType) {
                $query->where('relationship_type', $relationshipType);
            }

            $relationships = $query->get();

            foreach ($relationships as $rel) {
                $removed[] = "{$sourceName} --[{$rel->relationship_type}]--> {$targetName}";
                $rel->delete();
            }

            // Handle bidirectional
            if ($bidirectional) {
                $inverseQuery = MemoryRelationship::where('source_id', $targetId)
                    ->where('target_id', $sourceId);

                if ($relationshipType) {
                    $inverseType = MemoryRelationship::getInverseType($relationshipType) ?? $relationshipType;
                    $inverseQuery->where('relationship_type', $inverseType);
                }

                $inverseRelationships = $inverseQuery->get();

                foreach ($inverseRelationships as $rel) {
                    $removed[] = "{$targetName} --[{$rel->relationship_type}]--> {$sourceName}";
                    $rel->delete();
                }
            }

            if (empty($removed)) {
                return ToolResult::success("No matching relationships found to remove.");
            }

            $output = ["Removed " . count($removed) . " relationship(s):", ""];
            foreach ($removed as $rel) {
                $output[] = "  {$rel}";
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to remove relationship: ' . $e->getMessage());
        }
    }
}
