<?php

namespace Database\Seeders;

use App\Models\PocketTool;
use Illuminate\Database\Seeder;

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
            [
                'slug' => 'memory-link',
                'name' => 'Memory Link',
                'description' => 'Create a relationship between two memory objects.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryLinkPrompt(),
                'enabled' => true,
            ],
            [
                'slug' => 'memory-unlink',
                'name' => 'Memory Unlink',
                'description' => 'Remove a relationship between two memory objects.',
                'source' => PocketTool::SOURCE_POCKETDEV,
                'category' => PocketTool::CATEGORY_MEMORY,
                'capability' => PocketTool::CAPABILITY_MEMORY,
                'excluded_providers' => null,
                'native_equivalent' => null,
                'system_prompt' => $this->getMemoryUnlinkPrompt(),
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
    private function getMemoryCreatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:create` to create new memory objects.

## Usage
```bash
php artisan memory:create --structure=<slug> --name="<name>" [--data='<json>'] [--parent-id=<uuid>]
```

## Examples

Create a character:
```bash
php artisan memory:create --structure=character --name="Thorin Ironforge" --data='{"class":"fighter","level":5}'
```

Create a location with parent:
```bash
php artisan memory:create --structure=location --name="The Sunken Library" --data='{"terrain":"swamp"}' --parent-id=<parent-uuid>
```

## Notes
- Query available structures first: `php artisan memory:query --sql="SELECT slug, name FROM memory_structures"`
- Embeddings are automatically generated for fields marked with x-embed
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
- **memory_objects**: id, structure_id, structure_slug, name, data, searchable_text, parent_id
- **memory_embeddings**: id, object_id, field_path, embedding
- **memory_relationships**: id, source_id, target_id, relationship_type

## Examples

List structures:
```bash
php artisan memory:query --sql="SELECT slug, name FROM memory_structures"
```

List objects:
```bash
php artisan memory:query --sql="SELECT id, name, data FROM memory_objects WHERE structure_slug = 'character'"
```

Semantic search:
```bash
php artisan memory:query --sql="SELECT mo.id, mo.name FROM memory_objects mo JOIN memory_embeddings me ON mo.id = me.object_id ORDER BY me.embedding <=> :search_embedding LIMIT 10" --search-text="ancient library"
```
PROMPT;
    }

    private function getMemoryUpdatePrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:update` to modify existing memory objects.

## Usage
```bash
php artisan memory:update --id=<uuid> [--name="<name>"] [--data='<json>'] [--replace-data] [--parent-id=<uuid>]
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

    private function getMemoryLinkPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:link` to create relationships between objects.

## Usage
```bash
php artisan memory:link --source-id=<uuid> --target-id=<uuid> --type=<relationship> [--bidirectional]
```

## Common Types
- owns / owned_by
- contains / contained_in
- knows / known_by
- located_in / location_of

Use --bidirectional for symmetric relationships.
PROMPT;
    }

    private function getMemoryUnlinkPrompt(): string
    {
        return <<<'PROMPT'
Use `php artisan memory:unlink` to remove relationships.

## Usage
```bash
php artisan memory:unlink --source-id=<uuid> --target-id=<uuid> [--type=<relationship>] [--bidirectional]
```

Omit --type to remove all relationships between the objects.
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
