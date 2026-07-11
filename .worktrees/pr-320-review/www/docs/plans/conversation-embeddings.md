# Conversation Turn Embeddings

## Overview

Add semantic search for conversations by embedding turns (user question + assistant response cycles). Enables searching "when was X discussed" and navigating directly to that moment.

## Design Decisions

### What is a "Turn"?
A turn = real user message → all messages until next real user message (with at least one assistant response).

- **Real user message**: Has `text` type content blocks (not just `tool_result`)
- **Edge case**: User sends, gets interrupted immediately (no response) → grouped with next user message
- **Excludes**: Incomplete turns (no assistant response yet)

### What Gets Embedded Per Turn

| Content | Include | Notes |
|---------|---------|-------|
| User text blocks | Yes | The question/request |
| Assistant text blocks | Yes | The answer |
| Tool calls | Yes | Tool name + truncated params (250 chars each) |
| Thinking blocks | No | Internal reasoning, too verbose |
| Tool results | No | Technical output, noise |

### Chunking Strategy
- If turn text exceeds embedding model token limit (~6000 tokens)
- Split into chunks with ~500 token overlap
- Store each chunk with same `turn_number`, different `chunk_number`
- Search ranking uses best chunk score

### Locking Strategy
- Turn calculation runs INSIDE stream job while still `processing`
- Minimal lock time (<100ms for turn calculation)
- Embedding generation runs async AFTER status set to `idle`

## Architecture Overview

```
ProcessConversationStream (existing)
    │
    ├── Stream completes
    │
    ├── calculateTurns() ─────────────┐
    ├── Update messages.turn_number   │ Still 'processing' (locked)
    │                                 │
    ├── Set status = 'idle' ──────────┘
    │
    └── Dispatch GenerateConversationEmbeddings (async)
                │
                └── Generates embeddings (no lock needed)
```

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/xxxx_add_turn_tracking.php` | Create | Add `turn_number` to messages, `last_embedded_turn_number` to conversations |
| `database/migrations/xxxx_create_conversation_turn_embeddings.php` | Create | Embeddings table with vector index |
| `app/Models/ConversationTurnEmbedding.php` | Create | Eloquent model |
| `app/Models/Message.php` | Modify | Add `turn_number` to fillable |
| `app/Models/Conversation.php` | Modify | Add `last_embedded_turn_number` to fillable |
| `app/Jobs/ProcessConversationStream.php` | Modify | Add turn calculation methods, dispatch embedding job |
| `app/Jobs/GenerateConversationEmbeddings.php` | Create | Async job to generate embeddings |
| `app/Services/ConversationSearchService.php` | Create | Search logic with ranking (shared by controller + tool) |
| `app/Http/Controllers/Api/ConversationSearchController.php` | Create | API endpoint for UI |
| `app/Console/Commands/ConversationSearchCommand.php` | Create | Artisan command wrapper |
| `app/Tools/ConversationSearchTool.php` | Create | AI tool class (auto-discovered) |
| `routes/api.php` | Modify | Add search route |
| `resources/views/...` | Modify | Sidebar search UI |

## Database Schema

### Migration 1: Add turn tracking columns

```php
// messages table
Schema::table('messages', function (Blueprint $table) {
    $table->integer('turn_number')->nullable()->index();
});

// conversations table
Schema::table('conversations', function (Blueprint $table) {
    $table->integer('last_embedded_turn_number')->default(-1);  // -1 = no turns embedded yet
});
```

### Migration 2: Create embeddings table

```php
Schema::create('conversation_turn_embeddings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
    $table->integer('turn_number');
    $table->integer('chunk_number')->default(0);
    $table->vector('embedding', 1536);
    $table->text('content_preview')->nullable();
    $table->string('content_hash', 64);
    $table->timestamp('created_at')->useCurrent();

    $table->unique(['conversation_id', 'turn_number', 'chunk_number']);
    $table->index('conversation_id');
});

// Vector index (raw SQL)
DB::statement('CREATE INDEX idx_cte_vector ON conversation_turn_embeddings
    USING ivfflat (embedding vector_cosine_ops)');
```

## Implementation Details

### ProcessConversationStream (modify existing)

Add private methods for turn calculation (not a separate class):

```php
class ProcessConversationStream implements ShouldQueue
{
    // ... existing code ...

    /**
     * Called when stream completes successfully.
     * Calculates turns while still locked, then releases.
     */
    private function completeProcessing(): void
    {
        // Calculate and store turn numbers (while still 'processing')
        $this->calculateAndStoreTurns();

        // Release lock
        $this->conversation->update(['status' => Conversation::STATUS_IDLE]);

        // Dispatch async embedding job
        GenerateConversationEmbeddings::dispatch($this->conversation);
    }

    private function calculateAndStoreTurns(): void
    {
        $turns = $this->calculateTurns();

        DB::transaction(function () use ($turns) {
            foreach ($turns as $turnNumber => $messages) {
                $messageIds = collect($messages)->pluck('id');
                Message::whereIn('id', $messageIds)
                    ->update(['turn_number' => $turnNumber]);
            }
        });
    }

    private function calculateTurns(): array
    {
        $messages = $this->conversation->messages()->orderBy('sequence')->get();
        $turns = [];
        $currentTurn = null;
        $turnNumber = 0;
        $hasResponse = false;

        foreach ($messages as $message) {
            $isRealUserMessage = $message->role === 'user'
                && $this->hasRealUserContent($message);

            if ($isRealUserMessage) {
                if ($currentTurn !== null && $hasResponse) {
                    $turns[$turnNumber] = $currentTurn;
                    $turnNumber++;
                    $currentTurn = [];
                    $hasResponse = false;
                }
                $currentTurn = $currentTurn ?? [];
                $currentTurn[] = $message;
            } else {
                if ($currentTurn !== null) {
                    $currentTurn[] = $message;
                    if ($message->role === 'assistant') {
                        $hasResponse = true;
                    }
                }
            }
        }

        if ($currentTurn !== null && $hasResponse) {
            $turns[$turnNumber] = $currentTurn;
        }

        return $turns;
    }

    private function hasRealUserContent(Message $message): bool
    {
        if (!is_array($message->content)) {
            return is_string($message->content) && !empty($message->content);
        }
        return collect($message->content)
            ->contains(fn($block) => ($block['type'] ?? '') === 'text');
    }
}
```

### GenerateConversationEmbeddings (new job)

```php
class GenerateConversationEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function handle(): void
    {
        // Skip if conversation is being processed
        if ($this->conversation->fresh()->status === Conversation::STATUS_PROCESSING) {
            return;
        }

        $maxTurnNumber = $this->conversation->messages()->max('turn_number') ?? -1;

        // Skip if already up to date
        if ($maxTurnNumber <= $this->conversation->last_embedded_turn_number) {
            return;
        }

        // Only embed turns we haven't embedded yet
        $turns = $this->conversation->messages()
            ->whereNotNull('turn_number')
            ->where('turn_number', '>', $this->conversation->last_embedded_turn_number)
            ->orderBy('sequence')
            ->get()
            ->groupBy('turn_number');

        foreach ($turns as $turnNumber => $messages) {
            $this->embedTurn($turnNumber, $messages);
        }

        $this->conversation->update(['last_embedded_turn_number' => $maxTurnNumber]);
    }

    private function embedTurn(int $turnNumber, Collection $messages): void
    {
        $text = $this->buildTurnText($messages->all());
        $hash = hash('sha256', $text);

        $existing = ConversationTurnEmbedding::where('conversation_id', $this->conversation->id)
            ->where('turn_number', $turnNumber)
            ->where('chunk_number', 0)
            ->first();

        if ($existing && $existing->content_hash === $hash) {
            return;
        }

        // Delete old embeddings for this turn
        ConversationTurnEmbedding::where('conversation_id', $this->conversation->id)
            ->where('turn_number', $turnNumber)
            ->delete();

        // Generate embedding(s) - chunk if needed
        $this->generateAndStoreEmbedding($turnNumber, $text, $hash);
    }

    private function buildTurnText(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            foreach ($message->content as $block) {
                $type = $block['type'] ?? '';

                if ($type === 'text') {
                    $parts[] = $block['text'];
                } elseif ($type === 'tool_use') {
                    $parts[] = $this->formatToolCall($block);
                }
                // Skip: thinking, tool_result
            }
        }

        return implode("\n\n", $parts);
    }

    private function formatToolCall(array $block): string
    {
        $name = $block['name'] ?? 'unknown';
        $params = [];

        foreach ($block['input'] ?? [] as $key => $value) {
            $truncated = Str::limit((string) $value, 250, '...');
            $params[] = "{$key}:{$truncated}";
        }

        return $name . ' ' . implode(' ', $params);
    }

    private function generateAndStoreEmbedding(int $turnNumber, string $text, string $hash): void
    {
        // TODO: Implement with actual embedding service
        // Handle chunking if text too long
    }
}
```

### ConversationSearchService (new)

```php
class ConversationSearchService
{
    public function search(string $query, int $limit = 20): Collection
    {
        $queryEmbedding = $this->embed($query);

        $results = DB::select("
            SELECT
                cte.conversation_id,
                cte.turn_number,
                cte.content_preview,
                c.title as conversation_title,
                c.agent_id,
                1 - (cte.embedding <=> ?) as similarity
            FROM conversation_turn_embeddings cte
            JOIN conversations c ON c.id = cte.conversation_id
            WHERE c.status != 'archived'
            ORDER BY cte.embedding <=> ?
            LIMIT ?
        ", [$queryEmbedding, $queryEmbedding, $limit * 2]);

        // Group by turn, take best chunk score
        return collect($results)
            ->groupBy(fn($r) => $r->conversation_id . '-' . $r->turn_number)
            ->map(fn($chunks) => $chunks->sortByDesc('similarity')->first())
            ->sortByDesc('similarity')
            ->take($limit)
            ->map(fn($r) => $this->enrichWithUserQuestion($r));
    }

    private function enrichWithUserQuestion($result): object
    {
        $firstUserMessage = Message::where('conversation_id', $result->conversation_id)
            ->where('turn_number', $result->turn_number)
            ->where('role', 'user')
            ->orderBy('sequence')
            ->first();

        $result->user_question = $firstUserMessage?->getTextContent();
        $result->message_id = $firstUserMessage?->id;

        return $result;
    }
}
```

### API Endpoint

```php
// ConversationSearchController.php
public function search(Request $request, ConversationSearchService $search)
{
    $request->validate([
        'query' => 'required|string|min:2|max:500',
        'limit' => 'integer|min:1|max:50',
    ]);

    return response()->json([
        'results' => $search->search($request->query, $request->limit ?? 20)->values()
    ]);
}

// routes/api.php
Route::get('/conversations/search', [ConversationSearchController::class, 'search']);
```

### UI (sidebar)

- Search input above conversation list
- Results show: `{title}` / `{score}% - {user_question}` / `{date} · {agent}`
- Click navigates to `/conversations/{id}?turn={turnNumber}`
- Frontend scrolls to first message with `data-turn="{turnNumber}"`

### AI Tool: ConversationSearchTool

**File**: `app/Tools/ConversationSearchTool.php`

```php
class ConversationSearchTool extends Tool
{
    public string $name = 'ConversationSearch';
    public string $description = 'Search past conversations semantically';
    public string $category = 'memory';

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
- limit: Maximum results to return (default: 10)

## Output
Returns matching conversation turns ranked by relevance, including:
- Conversation ID (for navigation)
- Conversation title
- Similarity score (0-100%)
- The user question that started the turn
- Turn number (for scroll-to)
INSTRUCTIONS;

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Natural language search query (phrase or sentence, not keywords)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum results to return (default: 10)',
            ],
        ],
        'required' => ['query'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $service = app(ConversationSearchService::class);
        $results = $service->search(
            $input['query'],
            $input['limit'] ?? 10
        );

        return ToolResult::success($results->toJson());
    }

    public function getArtisanCommand(): ?string
    {
        return 'conversation:search';
    }
}
```

**Artisan command wrapper**: `app/Console/Commands/ConversationSearchCommand.php`

```php
class ConversationSearchCommand extends Command
{
    protected $signature = 'conversation:search
        {--query= : Search query}
        {--limit=10 : Max results}
        {--json : Output as JSON}';

    public function handle(ConversationSearchService $service): int
    {
        $results = $service->search(
            $this->option('query'),
            (int) $this->option('limit')
        );

        if ($this->option('json')) {
            $this->line($results->toJson());
        } else {
            // Human-readable output
            foreach ($results as $result) {
                $this->line("{$result->similarity}% - {$result->conversation_title}");
                $this->line("  {$result->user_question}");
                $this->line("  → /conversations/{$result->conversation_id}?turn={$result->turn_number}");
                $this->newLine();
            }
        }

        return Command::SUCCESS;
    }
}
```

## Future Optimizations

- **Batch API**: Use OpenAI's batch embedding API for cost savings
- **Conversation-level embedding**: Add centroid for coarse filtering
- **Background scheduler**: Catch missed conversations with periodic scan
