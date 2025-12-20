<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Models\MemoryRelationship;

/**
 * Create a relationship between two memory objects.
 */
class MemoryLinkTool extends Tool
{
    public string $name = 'MemoryLink';

    public string $description = 'Create a relationship between two memory objects.';

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
                'description' => 'Type of relationship (e.g., "owns", "knows", "located_in", "member_of", "contains").',
            ],
            'bidirectional' => [
                'type' => 'boolean',
                'description' => 'If true, also create the inverse relationship. Default: false.',
            ],
        ],
        'required' => ['source_id', 'target_id', 'relationship_type'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryLink to create relationships between objects.

## Common Relationship Types

- **owns** / **owned_by**: Character owns Item
- **contains** / **contained_in**: Location contains Location or Item
- **knows** / **known_by**: Character knows Character
- **located_in** / **location_of**: Character/Item located at Location
- **member_of** / **has_member**: Character member of Organization
- **parent_of** / **child_of**: Hierarchical relationships

## Examples

Character owns item:
```json
{
  "source_id": "character-uuid",
  "target_id": "sword-uuid",
  "relationship_type": "owns"
}
```

Bidirectional "knows" relationship:
```json
{
  "source_id": "char-a-uuid",
  "target_id": "char-b-uuid",
  "relationship_type": "knows",
  "bidirectional": true
}
```

## Notes
- Duplicate relationships are prevented (same source, target, and type)
- Use bidirectional for symmetric relationships like "knows"
- Inverse types are auto-detected (owns -> owned_by)
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $sourceId = $input['source_id'] ?? '';
        $targetId = $input['target_id'] ?? '';
        $relationshipType = $input['relationship_type'] ?? '';
        $bidirectional = $input['bidirectional'] ?? false;

        if (empty($sourceId)) {
            return ToolResult::error('source_id is required');
        }
        if (empty($targetId)) {
            return ToolResult::error('target_id is required');
        }
        if (empty($relationshipType)) {
            return ToolResult::error('relationship_type is required');
        }

        // Validate objects exist
        $source = MemoryObject::find($sourceId);
        if (!$source) {
            return ToolResult::error("Source object '{$sourceId}' not found.");
        }

        $target = MemoryObject::find($targetId);
        if (!$target) {
            return ToolResult::error("Target object '{$targetId}' not found.");
        }

        // Check for self-reference
        if ($sourceId === $targetId) {
            return ToolResult::error("Cannot create relationship from object to itself.");
        }

        try {
            $created = [];

            // Create primary relationship
            if (!MemoryRelationship::exists($sourceId, $targetId, $relationshipType)) {
                MemoryRelationship::create([
                    'source_id' => $sourceId,
                    'target_id' => $targetId,
                    'relationship_type' => $relationshipType,
                ]);
                $created[] = "{$source->name} --[{$relationshipType}]--> {$target->name}";
            } else {
                $created[] = "(already exists) {$source->name} --[{$relationshipType}]--> {$target->name}";
            }

            // Create inverse relationship if bidirectional
            if ($bidirectional) {
                $inverseType = MemoryRelationship::getInverseType($relationshipType) ?? $relationshipType;

                if (!MemoryRelationship::exists($targetId, $sourceId, $inverseType)) {
                    MemoryRelationship::create([
                        'source_id' => $targetId,
                        'target_id' => $sourceId,
                        'relationship_type' => $inverseType,
                    ]);
                    $created[] = "{$target->name} --[{$inverseType}]--> {$source->name}";
                } else {
                    $created[] = "(already exists) {$target->name} --[{$inverseType}]--> {$source->name}";
                }
            }

            $output = ["Created relationship(s):", ""];
            foreach ($created as $rel) {
                $output[] = "  {$rel}";
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to create relationship: ' . $e->getMessage());
        }
    }
}
