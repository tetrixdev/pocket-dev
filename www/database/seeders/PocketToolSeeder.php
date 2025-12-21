<?php

namespace Database\Seeders;

use App\Models\PocketTool;
use Illuminate\Database\Seeder;

/**
 * Seeds PocketDev's built-in tools with their system prompts.
 *
 * TODO: Consider extracting the large HEREDOC system prompts to separate files
 *       in resources/prompts/ for better maintainability. The seeder would then
 *       load prompts via file_get_contents() instead of inline HEREDOC blocks.
 */
class PocketToolSeeder extends Seeder
{
    public function run(): void
    {
        $tools = array_merge(
            $this->getFileOperationTools(),
            $this->getMemoryTools(),
            $this->getToolManagementTools()
        );

        foreach ($tools as $toolData) {
            PocketTool::updateOrCreate(
                ['slug' => $toolData['slug']],
                $toolData
            );
        }
    }

    private function getFileOperationTools(): array
    {
        return [
            [
                'slug' => 'pocketdev-bash',
                'name' => 'Bash',
                'description' => 'Execute bash commands in the terminal.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_BASH,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Bash',
                'system_prompt' => $this->getBashPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'pocketdev-read',
                'name' => 'Read',
                'description' => 'Read file contents.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_FILE_READ,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Read',
                'system_prompt' => $this->getReadPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'pocketdev-write',
                'name' => 'Write',
                'description' => 'Write content to a file.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_FILE_WRITE,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Write',
                'system_prompt' => $this->getWritePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'pocketdev-edit',
                'name' => 'Edit',
                'description' => 'Edit existing files with search and replace.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_FILE_EDIT,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Edit',
                'system_prompt' => $this->getEditPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'pocketdev-glob',
                'name' => 'Glob',
                'description' => 'Find files matching a glob pattern.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_FILE_GLOB,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Glob',
                'system_prompt' => $this->getGlobPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'pocketdev-grep',
                'name' => 'Grep',
                'description' => 'Search file contents with regex patterns.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_FILE_OPS,
                'capability' => PocketTool::CAPABILITY_FILE_GREP,
                'excluded_providers' => [PocketTool::PROVIDER_CLAUDE_CODE],
                'native_equivalent' => 'Grep',
                'system_prompt' => $this->getGrepPrompt(),
                'enabled' => true,
            ],
        ];
    }

    private function getMemoryTools(): array
    {
        return [
            [
                'slug' => 'memory-structure-create',
                'name' => 'Memory Structure Create',
                'description' => 'Create a new memory structure (schema/template for memory objects).',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryStructureCreatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-structure-get',
                'name' => 'Memory Structure Get',
                'description' => 'Get a memory structure schema by slug.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryStructureGetPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-structure-update',
                'name' => 'Memory Structure Update',
                'description' => 'Update an existing memory structure (name, description, schema, icon, color).',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryStructureUpdatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-structure-delete',
                'name' => 'Memory Structure Delete',
                'description' => 'Delete a memory structure (only if no objects exist).',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryStructureDeletePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-create',
                'name' => 'Memory Create',
                'description' => 'Create a new memory object of a specified structure type.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null, // Available for all providers
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryCreatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-query',
                'name' => 'Memory Query',
                'description' => 'Query memory objects using SQL with optional semantic search.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryQueryPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-update',
                'name' => 'Memory Update',
                'description' => 'Update an existing memory object by ID.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryUpdatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-delete',
                'name' => 'Memory Delete',
                'description' => 'Delete a memory object by ID.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryDeletePrompt(),
                'enabled' => true,
            ],
        ];
    }

    private function getToolManagementTools(): array
    {
        return [
            [
                'slug' => 'tool-create',
                'name' => 'Tool Create',
                'description' => 'Create a new custom tool with a bash script.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolCreatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'tool-update',
                'name' => 'Tool Update',
                'description' => 'Update an existing custom tool.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolUpdatePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'tool-delete',
                'name' => 'Tool Delete',
                'description' => 'Delete a custom tool.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolDeletePrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'tool-list',
                'name' => 'Tool List',
                'description' => 'List all available tools.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolListPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'tool-show',
                'name' => 'Tool Show',
                'description' => 'Show details of a specific tool.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolShowPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'tool-run',
                'name' => 'Tool Run',
                'description' => 'Run a custom tool by executing its bash script.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_TOOLS,
                'capability' => PocketTool::CAPABILITY_TOOL_MGMT,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getToolRunPrompt(),
                'enabled' => true,
            ],
        ];
    }

    // File operation prompts (brief since these have native equivalents)
    private function getBashPrompt(): string
    {
        return 'Execute bash commands in the terminal. Use for shell operations, git commands, package management, etc.';
    }

    private function getReadPrompt(): string
    {
        return 'Read the contents of a file. Specify the file path to read.';
    }

    private function getWritePrompt(): string
    {
        return 'Write content to a file. Creates the file if it does not exist.';
    }

    private function getEditPrompt(): string
    {
        return 'Edit a file using search and replace. Specify old content and new content.';
    }

    private function getGlobPrompt(): string
    {
        return 'Find files matching a glob pattern. Use patterns like "**/*.php" or "src/**/*.ts".';
    }

    private function getGrepPrompt(): string
    {
        return 'Search file contents using regex patterns. Returns matching lines with file paths.';
    }

    // Memory tool prompts
    private function getMemoryStructureCreatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:structure:create` to create new memory structures (schemas/templates).

## Usage
```bash
php artisan memory:structure:create --name="<name>" --description="<desc>" --schema='<json-schema>'
```

## Schema Format (JSON Schema)
- **type**: string, integer, boolean, object, array
- **properties**: Object defining each field
- **x-embed**: true on string fields enables vector embeddings for semantic search
- **required**: Array of required field names

## Example
```bash
php artisan memory:structure:create --name="Character" --description="A person or creature" --schema='{"type":"object","properties":{"class":{"type":"string"},"level":{"type":"integer"},"backstory":{"type":"string","x-embed":true}},"required":["class"]}'
```

## Notes
- Slug is auto-generated from name if not provided
- Use --icon and --color for UI customization
- Fields with x-embed:true enable semantic/vector search
PROMPT;
    }

    private function getMemoryStructureGetPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:structure:get` to retrieve a structure's schema.

## Usage
```bash
php artisan memory:structure:get <slug>
```

## Example
```bash
php artisan memory:structure:get character
```

Returns the structure's name, slug, description, schema, icon, and color.
PROMPT;
    }

    private function getMemoryStructureUpdatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:structure:update` to modify an existing memory structure.

## Usage
```bash
php artisan memory:structure:update <slug> [--name="<name>"] [--description="<desc>"] [--schema='<json>'] [--icon="<icon>"] [--color="<color>"]
```

## Safe Updates
These changes are always safe and have no impact on existing objects:
- `--name` - Change the display name
- `--description` - Change the description
- `--icon` - Change the icon
- `--color` - Change the color

## Schema Updates (Use with Caution)
Changing the schema can affect existing objects:
- **Adding fields**: Safe. Existing objects will have null values.
- **Removing fields**: Data in existing objects is orphaned but preserved.
- **Changing types**: May cause validation issues when updating existing objects.
- **Changing x-embed**: Use `--regenerate-embeddings` to update vectors.

## Examples

Update name and description:
```bash
php artisan memory:structure:update character --name="Player Character" --description="A playable character"
```

Update schema and regenerate embeddings:
```bash
php artisan memory:structure:update character --schema='{"type":"object","properties":{"class":{"type":"string"},"backstory":{"type":"string","x-embed":true}}}' --regenerate-embeddings
```

## Notes
- Warnings are displayed when schema changes may impact existing objects
- Use `--regenerate-embeddings` after changing x-embed markers
PROMPT;
    }

    private function getMemoryStructureDeletePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:structure:delete` to remove a memory structure.

## Usage
```bash
php artisan memory:structure:delete <slug>
```

## Example
```bash
php artisan memory:structure:delete character
```

## Notes
- Will fail if any objects exist for this structure
- Delete all objects of this structure type first
PROMPT;
    }

    private function getMemoryCreatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:create` to create new memory objects.

## Usage
```bash
php artisan memory:create --structure=<slug> --name="<name>" [--data='<json>']
```

## Examples

Create a character:
```bash
php artisan memory:create --structure=character --name="Thorin Ironforge" --data='{"class":"fighter","level":5}'
```

Create a location:
```bash
php artisan memory:create --structure=location --name="The Sunken Library" --data='{"terrain":"swamp","description":"A forgotten library..."}'
```

## Notes
- Query available structures first: `php artisan memory:query --sql="SELECT slug, name FROM memory_structures"`
- Embeddings are automatically generated for fields marked with x-embed
- Store relationships as IDs in the data object (e.g., {"owner_id": "uuid", "location_id": "uuid"})
- Returns the created object's ID
PROMPT;
    }

    private function getMemoryQueryPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:query` to search and retrieve memory objects.

## Usage
```bash
php artisan memory:query --sql="<SELECT query>" [--search-text="<text>"] [--limit=50]
```

## Available Tables
- **memory_structures**: id, name, slug, description, schema
- **memory_objects**: id, structure_id, structure_slug, name, data (JSONB), searchable_text
- **memory_embeddings**: id, object_id, field_path, embedding (vector)

## Examples

List structures:
```bash
php artisan memory:query --sql="SELECT slug, name, description FROM memory_structures"
```

List objects:
```bash
php artisan memory:query --sql="SELECT id, name, data FROM memory_objects WHERE structure_slug = 'character'"
```

Query JSONB data:
```bash
php artisan memory:query --sql="SELECT id, name, data->>'class' as class FROM memory_objects WHERE data->>'level' = '5'"
```

Semantic search:
```bash
php artisan memory:query --sql="SELECT mo.id, mo.name, 1 - (me.embedding <=> :search_embedding) as similarity FROM memory_objects mo JOIN memory_embeddings me ON mo.id = me.object_id ORDER BY similarity DESC LIMIT 10" --search-text="ancient library"
```

## Notes
- Use JSONB operators to query data fields: ->> for text, -> for JSON
- Use :search_embedding placeholder with --search-text for vector similarity
- Relationships are stored as IDs in the data object (e.g., data->>'owner_id')
PROMPT;
    }

    private function getMemoryUpdatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:update` to modify existing memory objects.

## Usage
```bash
php artisan memory:update --id=<uuid> [--name="<name>"] [--data='<json>'] [--replace-data]
```

## Text Operations (for long text fields)
```bash
# Append to a field
php artisan memory:update --id=<uuid> --field=backstory --append="\n\nNew chapter..."

# Prepend to a field
php artisan memory:update --id=<uuid> --field=notes --prepend="Important: "

# Replace text in a field
php artisan memory:update --id=<uuid> --field=description --replace-text="old text" --with="new text"

# Insert after a marker
php artisan memory:update --id=<uuid> --field=notes --insert-after="## Section A" --insert-text="\nNew content"
```

## Examples

Merge new data:
```bash
php artisan memory:update --id=<uuid> --data='{"level":6}'
```

Replace all data:
```bash
php artisan memory:update --id=<uuid> --replace-data --data='{"new":"data"}'
```

## Notes
- Data updates are merged by default (use --replace-data for full replacement)
- Text operations allow surgical edits to long text fields
- Embeddings are regenerated automatically for changed fields
PROMPT;
    }

    private function getMemoryDeletePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:delete` to remove memory objects.

## Usage
```bash
php artisan memory:delete --id=<uuid> [--cascade]
```

Use --cascade to also delete child objects.
PROMPT;
    }

    // Tool management prompts
    private function getToolCreatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:create` to create custom tools.

## Usage
```bash
php artisan tool:create --slug=<slug> --name="<name>" --description="<desc>" --system-prompt="<instructions>" --script='<bash script>'
```

## Example
```bash
php artisan tool:create --slug=git-status --name="Git Status" --description="Check git status" --system-prompt="Returns git branch and status" --script='#!/bin/bash
echo "{\"branch\": \"$(git branch --show-current)\"}"'
```

Scripts should output JSON for structured responses.
PROMPT;
    }

    private function getToolUpdatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:update` to modify custom tools.

## Usage
```bash
php artisan tool:update <slug> [--name="<name>"] [--script='<bash script>'] [--system-prompt="<instructions>"]
```

Only user-created tools can be updated.
PROMPT;
    }

    private function getToolDeletePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:delete` to remove custom tools.

## Usage
```bash
php artisan tool:delete <slug>
```

Only user-created tools can be deleted.
PROMPT;
    }

    private function getToolListPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:list` to see available tools.

## Usage
```bash
php artisan tool:list [--enabled] [--category=<cat>] [--json]
```

Options:
- --enabled: Show only enabled tools
- --category: Filter by category (memory, tools, custom)
- --json: Output as JSON
PROMPT;
    }

    private function getToolShowPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:show` to see tool details.

## Usage
```bash
php artisan tool:show <slug> [--script]
```

Use --script to include the bash script in output.
PROMPT;
    }

    private function getToolRunPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan tool:run` to execute custom tools.

## Usage
```bash
php artisan tool:run <slug> [-- <arguments>]
```

Arguments after -- are passed to the script.
PROMPT;
    }
}
