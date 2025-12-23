<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Update an existing memory object.
 */
class MemoryUpdateTool extends Tool
{
    public string $name = 'MemoryUpdate';

    public string $description = 'Update an existing memory object by ID.';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'string',
                'description' => 'The UUID of the object to update.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'New name for the object. Optional.',
            ],
            'data' => [
                'type' => 'object',
                'description' => 'Fields to update in the data. Will be merged with existing data.',
            ],
            'replace_data' => [
                'type' => 'boolean',
                'description' => 'If true, replace all data instead of merging. Default: false.',
            ],
            'text_operation' => [
                'type' => 'object',
                'description' => 'Text operation on a specific field (for long text fields).',
                'properties' => [
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name to operate on.',
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['append', 'prepend', 'replace', 'insert_after'],
                        'description' => 'The type of text operation.',
                    ],
                    'text' => [
                        'type' => 'string',
                        'description' => 'The text to append/prepend/insert.',
                    ],
                    'find' => [
                        'type' => 'string',
                        'description' => 'For replace: text to find. For insert_after: marker text.',
                    ],
                ],
                'required' => ['field', 'operation'],
            ],
        ],
        'required' => ['id'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:update';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryUpdate to modify existing memory objects.

## Examples

Update specific fields (merge):
```json
{
  "id": "object-uuid",
  "data": {"level": 6, "new_ability": "Rage"}
}
```

Replace all data:
```json
{
  "id": "object-uuid",
  "replace_data": true,
  "data": {"completely": "new data"}
}
```

## Text Operations (for long text fields)

Append to a field:
```json
{
  "id": "object-uuid",
  "text_operation": {"field": "backstory", "operation": "append", "text": "\n\n## New Chapter\nMore story here..."}
}
```

Replace text within a field:
```json
{
  "id": "object-uuid",
  "text_operation": {"field": "description", "operation": "replace", "find": "old text", "text": "new text"}
}
```

Insert after a marker:
```json
{
  "id": "object-uuid",
  "text_operation": {"field": "notes", "operation": "insert_after", "find": "## Section A", "text": "\nInserted content here"}
}
```

## Notes
- By default, data is merged (existing fields preserved, new fields added/updated)
- Use text_operation for surgical edits to long text fields without rewriting everything
- Embeddings are automatically regenerated for changed embeddable fields
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $id = $input['id'] ?? '';
        $name = $input['name'] ?? null;
        $data = $input['data'] ?? null;
        $replaceData = $input['replace_data'] ?? false;
        $textOp = $input['text_operation'] ?? null;

        if (empty($id)) {
            return ToolResult::error('id is required');
        }

        $object = MemoryObject::find($id);
        if (!$object) {
            return ToolResult::error("Object '{$id}' not found.");
        }

        try {
            $changes = [];

            DB::transaction(function () use ($object, $name, $data, $replaceData, $textOp, &$changes) {
                if ($name !== null && $name !== $object->name) {
                    $changes[] = "name: {$object->name} -> {$name}";
                    $object->name = $name;
                }

                // Handle text operations
                if ($textOp !== null) {
                    $result = $this->applyTextOperation($object, $textOp);
                    if ($result['success']) {
                        $changes[] = $result['change'];
                    } else {
                        throw new \RuntimeException($result['error']);
                    }
                }

                if ($data !== null) {
                    if ($replaceData) {
                        $changes[] = "data: replaced entirely";
                        $object->data = $data;
                    } else {
                        $merged = array_merge($object->data ?? [], $data);
                        $changedKeys = array_keys($data);
                        $changes[] = "data: updated fields [" . implode(', ', $changedKeys) . "]";
                        $object->data = $merged;
                    }
                }

                if (!empty($changes)) {
                    $object->save();
                    $object->refreshSearchableText();
                }
            });

            if (empty($changes)) {
                return ToolResult::success("No changes made to object '{$object->name}' ({$id}).");
            }

            // Regenerate embeddings
            $this->regenerateEmbeddings($object);

            $output = [
                "Updated {$object->structure_slug}: {$object->name}",
                "",
                "ID: {$id}",
                "",
                "Changes:",
            ];

            foreach ($changes as $change) {
                $output[] = "  - {$change}";
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to update object: ' . $e->getMessage());
        }
    }

    /**
     * Apply a text operation to a field.
     */
    private function applyTextOperation(MemoryObject $object, array $op): array
    {
        $field = $op['field'] ?? null;
        $operation = $op['operation'] ?? null;
        $text = $op['text'] ?? '';
        $find = $op['find'] ?? '';

        if (!$field || !$operation) {
            return ['success' => false, 'error' => 'text_operation requires field and operation'];
        }

        $data = $object->data ?? [];
        $currentValue = $data[$field] ?? '';

        if (!is_string($currentValue)) {
            return ['success' => false, 'error' => "Field '{$field}' is not a string"];
        }

        switch ($operation) {
            case 'append':
                $data[$field] = $currentValue . $text;
                $change = "text: appended to '{$field}'";
                break;

            case 'prepend':
                $data[$field] = $text . $currentValue;
                $change = "text: prepended to '{$field}'";
                break;

            case 'replace':
                if (empty($find)) {
                    return ['success' => false, 'error' => 'replace operation requires find parameter'];
                }
                if (strpos($currentValue, $find) === false) {
                    return ['success' => false, 'error' => "Text '{$find}' not found in field '{$field}'"];
                }
                $data[$field] = str_replace($find, $text, $currentValue);
                $change = "text: replaced in '{$field}'";
                break;

            case 'insert_after':
                if (empty($find)) {
                    return ['success' => false, 'error' => 'insert_after operation requires find parameter'];
                }
                $pos = strpos($currentValue, $find);
                if ($pos === false) {
                    return ['success' => false, 'error' => "Marker '{$find}' not found in field '{$field}'"];
                }
                $insertPos = $pos + strlen($find);
                $data[$field] = substr($currentValue, 0, $insertPos) . $text . substr($currentValue, $insertPos);
                $change = "text: inserted after marker in '{$field}'";
                break;

            default:
                return ['success' => false, 'error' => "Unknown operation: {$operation}"];
        }

        $object->data = $data;
        return ['success' => true, 'change' => $change];
    }

    private function regenerateEmbeddings(MemoryObject $object): void
    {
        try {
            $service = app(EmbeddingService::class);
            if ($service->isAvailable()) {
                $service->embedObject($object);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to regenerate embeddings for object', [
                'object_id' => $object->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
