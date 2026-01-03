<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationSearchService
{
    public function __construct(
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Search conversation turns semantically.
     *
     * @param string $query Natural language search query
     * @param int $limit Maximum results to return
     * @param bool $includeArchived Whether to include archived conversations
     * @return Collection Collection of search results
     */
    public function search(string $query, int $limit = 20, bool $includeArchived = false): Collection
    {
        if (!$this->embeddingService->isAvailable()) {
            Log::warning('ConversationSearchService: Embedding service not available');
            return collect();
        }

        // Generate embedding for query
        $queryEmbedding = $this->embeddingService->embed($query);

        if ($queryEmbedding === null) {
            Log::warning('ConversationSearchService: Failed to embed query');
            return collect();
        }

        // Format embedding for PostgreSQL
        $embeddingString = '[' . implode(',', $queryEmbedding) . ']';

        // Build status filter - always exclude soft-deleted, optionally exclude archived
        $statusFilter = $includeArchived ? '' : 'AND c.status != ?';
        $params = $includeArchived
            ? [$embeddingString, $embeddingString, $limit * 3]
            : [$embeddingString, Conversation::STATUS_ARCHIVED, $embeddingString, $limit * 3];

        // Search turn embeddings using cosine similarity
        // Fetch extra results to account for grouping by turn (best chunk per turn)
        $results = DB::select("
            SELECT
                cte.conversation_id,
                cte.turn_number,
                cte.chunk_number,
                cte.content_preview,
                c.title as conversation_title,
                c.uuid as conversation_uuid,
                c.agent_id,
                c.updated_at as conversation_updated_at,
                ROUND(((1 - (cte.embedding <=> ?::vector)) * 100)::numeric, 1) as similarity
            FROM conversation_turn_embeddings cte
            JOIN conversations c ON c.id = cte.conversation_id
            WHERE c.deleted_at IS NULL
              {$statusFilter}
            ORDER BY cte.embedding <=> ?::vector
            LIMIT ?
        ", $params);

        // Group by turn, take best chunk score per turn
        $grouped = collect($results)
            ->groupBy(fn($r) => $r->conversation_id . '-' . $r->turn_number)
            ->map(fn($chunks) => $chunks->sortByDesc('similarity')->first())
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();

        // Enrich with first user message of each turn
        return $grouped->map(fn($result) => $this->enrichWithUserQuestion($result));
    }

    /**
     * Add the user question that started the turn.
     */
    private function enrichWithUserQuestion(object $result): object
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

    /**
     * Check if the search service is available.
     */
    public function isAvailable(): bool
    {
        return $this->embeddingService->isAvailable();
    }
}
