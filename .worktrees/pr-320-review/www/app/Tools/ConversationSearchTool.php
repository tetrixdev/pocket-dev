<?php

namespace App\Tools;

use App\Services\ConversationSearchService;

/**
 * Search past conversations semantically.
 */
class ConversationSearchTool extends Tool
{
    public string $name = 'ConversationSearch';

    public string $description = 'Search past conversations semantically to find when topics were discussed.';

    public string $category = 'memory_data';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Natural language search query (phrase or sentence, not keywords)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum results to return (default: 5)',
            ],
        ],
        'required' => ['query'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Search past conversations semantically to find when topics were discussed.

## When to Use
- Finding previous discussions about a topic
- Looking up how something was implemented before
- Recalling past decisions or explanations

## Query Best Practices

IMPORTANT: Semantic search works on meaning, not keywords. Your query should be natural language - a phrase or sentence that captures what you're looking for.

**Good queries** (preserve the user's phrasing):
- "How do I set up JWT authentication"
- "database migration for user permissions"
- "fixing the memory leak in the queue worker"

**Bad queries** (extracted keywords lose semantic meaning):
- "JWT auth setup"
- "migration permissions"
- "memory leak queue"

When the user asks about something, pass their question or a relevant portion of it directly. Do not reduce it to keywords - the embedding model needs sentence-level context to understand meaning.

## Parameters
- query: Natural language search query (phrase or sentence, not keywords)
- limit: Maximum results to return (default: 5)

## Output
Returns matching conversation turns ranked by relevance, including:
- Conversation URL (clickable link that scrolls to the turn)
- Conversation title
- Similarity score (0-100%)
- Content preview (previous context + user question + assistant response)
- Turn number
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd conversation:search --query="How do I set up JWT authentication"
pd conversation:search --query="database migration for user permissions" --limit=5
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "query": "How do I set up JWT authentication",
  "limit": 10
}
```
API;

    public function getArtisanCommand(): ?string
    {
        return 'conversation:search';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $query = trim($input['query'] ?? '');
        $limit = min(50, max(1, $input['limit'] ?? 5));

        if (empty($query)) {
            return ToolResult::error('query is required');
        }

        if (strlen($query) < 2) {
            return ToolResult::error('query must be at least 2 characters');
        }

        $service = app(ConversationSearchService::class);

        if (!$service->isAvailable()) {
            return ToolResult::error('Search service not available. Please configure OpenAI API key for embeddings.');
        }

        $results = $service->search($query, $limit);

        if ($results->isEmpty()) {
            return ToolResult::success(json_encode([
                'results' => [],
                'count' => 0,
                'message' => 'No matching conversations found.',
            ], JSON_PRETTY_PRINT));
        }

        // Format results for output
        $formatted = $results->map(fn($r) => [
            'url' => "/chat/{$r->conversation_uuid}?turn={$r->turn_number}",
            'conversation_uuid' => $r->conversation_uuid,
            'conversation_title' => $r->conversation_title,
            'turn_number' => $r->turn_number,
            'similarity' => $r->similarity . '%',
            'content_preview' => $r->content_preview,
            'user_question' => $r->user_question,
        ])->values()->all();

        return ToolResult::success(json_encode([
            'results' => $formatted,
            'count' => count($formatted),
        ], JSON_PRETTY_PRINT));
    }
}
